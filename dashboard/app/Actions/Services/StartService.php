<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Deployments\RedeployService;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DeploymentTrigger;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\ServiceProcesses;
use App\Services\Deployments\ImageRerun;
use Illuminate\Support\Facades\DB;

/**
 * Starts a stopped service (D68): a `start` deployment runs the version that
 * was live when it stopped (its image, configuration and environment; no
 * build, no release command) and goes through the usual rollout: placed,
 * health-checked, routed. When the environment deployed configuration while
 * it was stopped, or the image is gone from the registry, it is a redeploy
 * of the last deployed configuration at the same commit instead. A service that never ran
 * starts with the next deploy.
 *
 * With `processes`, an app's stopped workers and crons start with it.
 */
final readonly class StartService
{
    public function __construct(
        private ImageRerun $rerun,
        private RedeployService $redeploy,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @return list<Deployment>
     */
    public function handle(ServiceInstance $instance, bool $processes, User $user, AuditActor $actor): array
    {
        $redeploy = [];

        $created = DB::transaction(function () use ($instance, $processes, $user, $actor, &$redeploy): array {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'activeDeployment'])->lockForUpdate()->firstOrFail();

            if ($instance->stopped_at === null) {
                throw DomainRuleViolation::on('service', 'This service is running already.');
            }

            $targets = [$instance];

            if ($processes) {
                foreach (ServiceProcesses::of($instance) as $process) {
                    if ($process->stopped_at !== null) {
                        $targets[] = ServiceInstance::query()->whereKey($process->id)->with(['service', 'activeDeployment'])->lockForUpdate()->firstOrFail();
                    }
                }
            }

            $environment = Environment::query()->whereKey($instance->environment_id)->with('lastAppliedRevision')->firstOrFail();
            $deployments = [];
            $names = [];

            foreach ($targets as $target) {
                $stoppedAt = $target->stopped_at;
                $target->forceFill(['stopped_at' => null, 'stopped_by_id' => null])->save();
                $names[] = $target->service->public_id;
                $active = $target->activeDeployment;

                if ($active === null) {
                    continue;
                }

                // Configuration deployed while it was stopped runs now; what
                // failed before it stopped doesn't (the live version stays).
                $revision = $environment->lastAppliedRevision;
                $changedWhileStopped = $revision !== null && $revision->id !== $active->revision_id && $stoppedAt !== null && $revision->created_at->greaterThan($stoppedAt);

                if (ImageRerun::possible($active) && ! $changedWhileStopped) {
                    $deployments[] = $this->rerun->create($active, DeploymentTrigger::Start, $user);
                } else {
                    $redeploy[] = $target;
                }
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServiceStarted,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['environment' => $instance->environment_id, 'instance' => $instance->public_id, 'services' => $names],
            ));

            return $deployments;
        });

        foreach ($created as $deployment) {
            dispatch(new AdvanceDeploymentJob($deployment->id));
        }

        // Redeploys advance themselves.
        foreach ($redeploy as $target) {
            $created[] = $this->redeploy->handle($target, $target->activeDeployment, $user, $actor, DeploymentTrigger::Start);
        }

        // The edges keep answering "stopped" until the new version is routed.
        event(new NodeStateInvalidated(ServiceProcesses::edges($instance->team_id)));

        return $created;
    }
}
