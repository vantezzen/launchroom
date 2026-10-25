<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Security\BuildInvestigationReportJob;
use App\Models\DaemonTask;
use App\Models\Placement;
use App\Models\Security\SecurityFinding;
use App\Models\Security\SecurityInvestigation;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Support\Variables\SecretTaint;
use Illuminate\Support\Facades\DB;

/**
 * Starts an investigation of a service instance (docs/22 §7.1–7.2): one
 * `inspect_container` task per running replica, pinned to its server, one
 * attempt, deadline 3 minutes, with the names of the workload's secret
 * values to mask in process commands. Replicas on servers that are offline
 * or can't inspect yet are recorded as unchecked; without any task the
 * report is built right away (partial).
 *
 * Automatic once per finding when it becomes "Action needed" (an existing
 * investigation of the finding is returned instead); by hand at most 6 per
 * hour and instance (`security.investigation_started` audited).
 */
final readonly class StartInvestigation
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @throws DomainRuleViolation when manual starts are throttled
     */
    public function handle(ServiceInstance $instance, ?SecurityFinding $finding, ?User $user, bool $automatic = false, ?AuditActor $actor = null): ?SecurityInvestigation
    {
        if ($automatic && $finding?->investigation_id !== null) {
            return SecurityInvestigation::query()->whereKey($finding->investigation_id)->first();
        }

        return DB::transaction(function () use ($instance, $finding, $user, $automatic, $actor): SecurityInvestigation {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['environment.project', 'service', 'activeDeployment.revision'])->lockForUpdate()->firstOrFail();

            if (! $automatic) {
                $recent = SecurityInvestigation::query()
                    ->where('service_instance_id', $instance->id)
                    ->where('automatic', false)
                    ->where('started_at', '>=', now()->subHour())
                    ->count();

                if ($recent >= config()->integer('launchroom.security.investigations_per_hour', 6)) {
                    throw DomainRuleViolation::on('investigation', 'This service was checked several times in the last hour. Try again a bit later.');
                }
            }

            $investigation = SecurityInvestigation::query()->create([
                'team_id' => $instance->team_id,
                'project_id' => $instance->environment->project_id,
                'service_instance_id' => $instance->id,
                'finding_id' => $finding?->id,
                'started_by' => $user?->id,
                'automatic' => $automatic,
                'started_at' => now(),
            ]);

            $replicas = $this->inspect($instance);
            $investigation->forceFill(['replicas' => $replicas])->save();

            if ($finding instanceof SecurityFinding) {
                $finding->forceFill(['investigation_id' => $investigation->id])->save();
            }

            if (! $automatic && $actor instanceof AuditActor) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::SecurityInvestigationStarted,
                    actor: $actor,
                    teamId: $instance->team_id,
                    subjectType: 'service',
                    subjectId: $instance->service->public_id,
                    meta: array_filter([
                        'investigation' => $investigation->public_id,
                        'environment' => $instance->environment->public_id,
                        'finding' => $finding?->public_id,
                    ]),
                ));
            }

            // Nothing to wait for: write the (partial) report now.
            if (array_filter($replicas, static fn (array $r): bool => $r['state'] === 'pending') === []) {
                dispatch(new BuildInvestigationReportJob($investigation->id))->afterCommit();
            }

            return $investigation;
        });
    }

    /**
     * @return list<array{workload: string, server: string, serverName: string, slot: int, task: string|null, state: string, message?: string|null}>
     */
    private function inspect(ServiceInstance $instance): array
    {
        $deployment = $instance->activeDeployment;

        if ($deployment === null) {
            return [];
        }

        $placements = Placement::query()
            ->where('deployment_id', $deployment->id)
            ->where('role', WorkloadRole::Serving->value)
            ->whereIn('phase', [WorkloadPhase::Running->value, WorkloadPhase::Healthy->value, WorkloadPhase::Unhealthy->value])
            ->with('server')
            ->orderBy('slot')
            ->get();

        $revision = $deployment->revision;
        $spec = $revision === null ? null : rescue(static fn () => $revision->spec);
        $mask = $spec === null ? [] : SecretTaint::fromSpec($spec)->taintedKeys($spec->service($instance->service->public_id)->slug ?? '');
        $replicas = [];

        foreach ($placements as $placement) {
            $server = $placement->server;
            $replica = ['workload' => $placement->workload_id, 'server' => $server->public_id, 'serverName' => $server->name, 'slot' => $placement->slot, 'task' => null];

            if ($server->status !== ServerStatus::Online) {
                $replicas[] = [...$replica, 'state' => 'offline'];

                continue;
            }

            if (! $server->supportsDaemonFeature(DaemonFeature::ContainerInspection)) {
                $replicas[] = [...$replica, 'state' => 'unsupported'];

                continue;
            }

            $task = DaemonTask::query()->create([
                'team_id' => $instance->team_id,
                'server_id' => $server->id,
                'pinned_to_server' => true,
                'required_role' => null,
                'type' => DaemonTaskType::InspectContainer,
                'spec' => json_decode(json_encode([
                    'workload' => $placement->workload_id,
                    ...($mask === [] ? [] : ['maskEnv' => array_slice($mask, 0, 1024)]),
                ], JSON_THROW_ON_ERROR), false, 8, JSON_THROW_ON_ERROR),
                'max_attempts' => 1,
                'timeout_seconds' => config()->integer('launchroom.security.inspection_timeout_seconds', 180),
            ]);

            $replicas[] = [...$replica, 'task' => $task->public_id, 'state' => 'pending'];
        }

        return $replicas;
    }
}
