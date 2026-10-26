<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Deployments\ImageRerun;
use App\Support\Services\ServiceProcesses;
use Illuminate\Support\Facades\DB;

/**
 * Restarts a running service (D68): a `restart` deployment of the live
 * version (same image, configuration and environment; no build, no release
 * command) recreates its containers with new workload ids. The rollout is
 * the usual one: several replicas roll one at a time behind health checks
 * (docs/04 §8), a single stateless replica starts next to the old one
 * before traffic moves, a database stops and starts. Staged changes stay
 * staged. Refused while a deployment of it is unfinished.
 *
 * With `processes`, an app's running workers and crons restart with it.
 */
final readonly class RestartService
{
    public function __construct(
        private ImageRerun $rerun,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @return list<Deployment>
     */
    public function handle(ServiceInstance $instance, bool $processes, User $user, AuditActor $actor): array
    {
        $created = DB::transaction(function () use ($instance, $processes, $user, $actor): array {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'activeDeployment'])->lockForUpdate()->firstOrFail();

            if ($instance->stopped_at !== null) {
                throw DomainRuleViolation::on('service', 'This service is stopped. Start it instead.');
            }

            $targets = [$instance];

            if ($processes) {
                foreach (ServiceProcesses::of($instance) as $process) {
                    if ($process->stopped_at === null && $process->activeDeployment !== null) {
                        $targets[] = ServiceInstance::query()->whereKey($process->id)->with(['service', 'activeDeployment'])->lockForUpdate()->firstOrFail();
                    }
                }
            }

            $deployments = [];

            foreach ($targets as $target) {
                $active = $target->activeDeployment;

                if ($active === null) {
                    throw DomainRuleViolation::on('service', "{$target->service->name} isn’t running yet. Deploy it first.");
                }

                $unfinished = Deployment::query()
                    ->where('service_instance_id', $target->id)
                    ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight()]))
                    ->exists();

                if ($unfinished) {
                    throw DomainRuleViolation::conflict("A deployment of {$target->service->name} is in progress. It starts fresh containers anyway; restart once it finished if you still need to.");
                }

                if (! ImageRerun::possible($active)) {
                    throw DomainRuleViolation::on('service', "The image {$target->service->name} runs is no longer stored. Redeploy it instead.");
                }

                $deployments[] = $this->rerun->create($active, DeploymentTrigger::Restart, $user);
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServiceRestarted,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: [
                    'environment' => $instance->environment_id,
                    'instance' => $instance->public_id,
                    'deployments' => array_map(static fn (Deployment $deployment): string => $deployment->public_id, $deployments),
                ],
            ));

            return $deployments;
        });

        foreach ($created as $deployment) {
            dispatch(new AdvanceDeploymentJob($deployment->id));
        }

        return $created;
    }
}
