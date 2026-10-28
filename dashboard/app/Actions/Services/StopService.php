<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Deployments\RetiredWorkloads;
use App\Support\Services\ServiceProcesses;
use Illuminate\Support\Facades\DB;

/**
 * Stops a service in an environment (D68, docs/04 §8.1): it keeps its
 * configuration, domains, volumes and deployments but runs no containers.
 * Its workloads leave the servers' desired state (launchroomd stops and
 * removes them), its domains answer with the edge's "stopped" page, and
 * deployments that haven't finished are cancelled (AdvanceDeployment).
 * Backups of it are skipped and no "down" alert fires while it is stopped.
 * Not a staged change: it applies now and is not part of a revision.
 *
 * With `processes`, an app's workers and crons stop with it. Refused while a
 * deployment switches traffic (it can't be cancelled at that point).
 */
final readonly class StopService
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @return list<string> the public ids of the services stopped
     */
    public function handle(ServiceInstance $instance, bool $processes, User $user, AuditActor $actor): array
    {
        $servers = [];
        $advance = [];

        $stopped = DB::transaction(function () use ($instance, $processes, $user, $actor, &$servers, &$advance): array {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with('service')->lockForUpdate()->firstOrFail();

            if ($instance->stopped_at !== null) {
                throw DomainRuleViolation::on('service', 'This service is stopped already.');
            }

            if ($instance->active_deployment_id === null && ! $this->hasUnfinished($instance)) {
                throw DomainRuleViolation::on('service', 'This service isn’t running in this environment.');
            }

            $targets = [$instance];

            if ($processes) {
                foreach (ServiceProcesses::of($instance) as $process) {
                    if ($process->stopped_at === null) {
                        $targets[] = ServiceInstance::query()->whereKey($process->id)->with('service')->lockForUpdate()->firstOrFail();
                    }
                }
            }

            $names = [];

            foreach ($targets as $target) {
                if (Deployment::query()->where('service_instance_id', $target->id)->where('status', DeploymentStatus::Routing->value)->exists()) {
                    throw DomainRuleViolation::conflict("{$target->service->name} is switching traffic to a new version right now. Stop it once that finished.");
                }

                $target->forceFill(['stopped_at' => now(), 'stopped_by_id' => $user->id])->save();

                $placements = Placement::query()->where('service_instance_id', $target->id)->lockForUpdate()->get();
                $servers = [...$servers, ...$placements->pluck('server_id')->all()];
                RetiredWorkloads::remember($placements);
                Placement::query()->whereKey($placements->modelKeys())->delete();

                $advance = [...$advance, ...Deployment::query()
                    ->where('service_instance_id', $target->id)
                    ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight()]))
                    ->pluck('id')
                    ->all()];
                $names[] = $target->service->public_id;
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServiceStopped,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['environment' => $instance->environment_id, 'instance' => $instance->public_id, 'services' => $names],
            ));

            return $names;
        });

        // Their servers stop the containers; the edges answer "stopped".
        /** @var list<int> $servers */
        event(new NodeStateInvalidated(array_values(array_unique([...$servers, ...ServiceProcesses::edges($instance->team_id)]))));

        foreach ($advance as $id) {
            if (is_int($id)) {
                dispatch(new AdvanceDeploymentJob($id));
            }
        }

        return $stopped;
    }

    private function hasUnfinished(ServiceInstance $instance): bool
    {
        return Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight()]))
            ->exists();
    }
}
