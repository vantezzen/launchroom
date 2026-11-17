<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Data\Audit\AuditActor;
use App\Data\Holds\Hold;
use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use App\Enums\WorkloadIssueKind;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Placement;
use App\Services\Holds\Holds;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Probation after go-live (docs/04 §8, OD1): a production deployment of a
 * new version is watched for `launchroom.deployments.probation_seconds`
 * after it went live. If it crash-loops (or launchroomd gave up restarting
 * it) or every replica is down for `probation_down_seconds`, launchroom
 * rolls back once to the version it replaced, through RollbackDeployment:
 * health-gated like any deploy (a previous version that fails too leaves
 * the current one live), production auto-deploy paused, the reason kept on
 * the rollback deployment. Database migrations are not reverted, and the
 * reason says so when a release command ran.
 *
 * Never for databases (nor images with a volume), workers, crons or
 * previews; never for restarts, starts and rollbacks (no ping-pong); at
 * most once per service and
 * `auto_rollback_cooldown_seconds`; not while a newer deployment of the
 * service is queued or running (someone is fixing it) or an import or
 * restore holds it. Reports and the minute sweep call it through
 * AdvanceDeployment; idempotent.
 */
final readonly class CheckProbation
{
    public function __construct(
        private RollbackDeployment $rollback,
    ) {}

    /**
     * Returns the rollback deployment it started, if any.
     */
    public function handle(int $deploymentId): ?Deployment
    {
        $probe = Deployment::query()->whereKey($deploymentId)->first(['id', 'status', 'probation_until']);

        if (! $probe instanceof Deployment || ! $probe->inProbation()) {
            return null;
        }

        return DB::transaction(function () use ($deploymentId): ?Deployment {
            $deployment = Deployment::query()
                ->whereKey($deploymentId)
                ->with(['serviceInstance.service', 'environment'])
                ->lockForUpdate()
                ->first();

            if (! $deployment instanceof Deployment || ! $deployment->inProbation()) {
                return null;
            }

            $instance = $deployment->serviceInstance;

            if ($instance->active_deployment_id !== $deployment->id || $instance->stopped_at !== null) {
                return $this->end($deployment);
            }

            $failure = $this->failure($deployment);

            if ($failure === null) {
                return null;
            }

            $cooldown = config()->integer('launchroom.deployments.auto_rollback_cooldown_seconds');

            if ($instance->last_auto_rollback_at?->greaterThan(now()->subSeconds($cooldown)) ?? false) {
                return $this->end($deployment);
            }

            // Someone deploys a fix (or an import or restore holds it): their move.
            $newer = Deployment::query()
                ->where('service_instance_id', $instance->id)
                ->where('id', '>', $deployment->id)
                ->unfinished()
                ->exists();

            if ($newer || Holds::blocking($instance->id) instanceof Hold) {
                return $this->end($deployment);
            }

            $target = Deployment::query()
                ->where('service_instance_id', $instance->id)
                ->where('superseded_by_id', $deployment->id)
                ->wentLive()
                ->latest('went_live_at')
                ->first();

            if (! $target instanceof Deployment || ! $target->isRollbackTarget()) {
                return $this->end($deployment);
            }

            $reason = "{$deployment->public_id} {$failure} after it went live, so launchroom rolled back to {$target->public_id}.";

            if ($this->ranRelease($deployment)) {
                $reason .= " Database migrations of {$deployment->public_id} were not reverted.";
            }

            $rollback = $this->rollback->handle($target, null, AuditActor::system(), $reason);
            $instance->forceFill(['last_auto_rollback_at' => now()])->save();
            $this->end($deployment);
            Cache::forget($this->downKey($deployment));

            return $rollback;
        });
    }

    /**
     * Ends the watch (kept as the moment it ended).
     */
    private function end(Deployment $deployment): null
    {
        $deployment->forceFill(['probation_until' => now()])->save();

        return null;
    }

    /**
     * What went wrong, as the middle of "dpl_… <failure> after it went
     * live", or null while it looks fine. Decided only on fresh reports: a
     * server that stopped reporting says nothing about the app.
     */
    private function failure(Deployment $deployment): ?string
    {
        /** @var Collection<int, Placement> $placements */
        $placements = $deployment->placements()->where('role', WorkloadRole::Serving->value)->get();

        if ($placements->isEmpty() || $placements->contains(static fn (Placement $placement): bool => $placement->observed_at === null || $placement->observed_at->lessThan(now()->subSeconds(90)))) {
            return null;
        }

        $crashing = $placements->first(static fn (Placement $placement): bool => in_array($placement->issue?->kind, [WorkloadIssueKind::CrashLoop, WorkloadIssueKind::Unresponsive], true));

        if ($crashing instanceof Placement && $crashing->issue !== null) {
            $issue = $crashing->issue;

            if ($issue->kind === WorkloadIssueKind::Unresponsive) {
                return 'stopped answering its health check';
            }

            if ($issue->oomKilled) {
                return 'kept running out of memory';
            }

            $restarts = $issue->restarts ?? $crashing->restart_count;

            return 'kept crashing (exited'.($issue->exitCode === null ? '' : " with code {$issue->exitCode}").", restarted {$restarts} times)";
        }

        $down = $placements->every(static fn (Placement $placement): bool => in_array($placement->phase, [WorkloadPhase::Exited, WorkloadPhase::Failed, WorkloadPhase::Removed, WorkloadPhase::Unhealthy], true));

        if (! $down) {
            Cache::forget($this->downKey($deployment));

            return null;
        }

        $seconds = config()->integer('launchroom.deployments.probation_down_seconds');
        Cache::add($this->downKey($deployment), now()->getTimestamp(), $seconds * 10);
        $since = Cache::get($this->downKey($deployment));

        if (! is_int($since) || now()->getTimestamp() - $since < $seconds) {
            return null;
        }

        return $placements->count() === 1 ? 'was down for '.$this->minutes($seconds) : 'had every replica down for '.$this->minutes($seconds);
    }

    private function ranRelease(Deployment $deployment): bool
    {
        return DeploymentStep::query()
            ->where('deployment_id', $deployment->id)
            ->where('name', DeploymentStepName::Release->value)
            ->where('status', DeploymentStepStatus::Succeeded->value)
            ->exists();
    }

    private function downKey(Deployment $deployment): string
    {
        return "deployment-probation-down:{$deployment->id}";
    }

    private function minutes(int $seconds): string
    {
        return $seconds % 60 === 0 ? ($seconds / 60).' minutes' : "{$seconds} seconds";
    }
}
