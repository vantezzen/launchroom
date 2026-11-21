<?php

declare(strict_types=1);

namespace App\Actions\AppUpdates;

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Services\ChangeServiceImage;
use App\Data\Audit\AuditActor;
use App\Data\Services\ChangeImageData;
use App\Enums\AppUpdates\AppUpdateStatus;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AppUpdates\CheckAppUpdatesJob;
use App\Models\AppUpdates\AppUpdate;
use App\Models\Backups\BackupRun;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\AppUpdates\UpdatePlan;
use Illuminate\Support\Facades\DB;

/**
 * Moves one app update along (docs/04 §14.2), idempotently, with the update
 * row locked: waits for its backups, then changes the image and deploys
 * (trigger `update`), then follows the deployment through go-live and its
 * probation. Called when a deployment or backup run finishes and by the
 * minute tick.
 */
final readonly class AdvanceAppUpdate
{
    /** A backup step longer than this ends the update. */
    public const int BACKUP_TIMEOUT_MINUTES = 120;

    public function __construct(
        private UpdatePlan $plan,
        private ChangeServiceImage $changeImage,
        private DeployEnvironment $deploy,
    ) {}

    public function handle(int $updateId): void
    {
        DB::transaction(function () use ($updateId): void {
            $update = AppUpdate::query()->whereKey($updateId)->with(['serviceInstance.service', 'serviceInstance.environment'])->lockForUpdate()->first();

            if (! $update instanceof AppUpdate || ! $update->status->isOpen()) {
                return;
            }

            match ($update->status) {
                AppUpdateStatus::BackingUp => $this->afterBackups($update),
                AppUpdateStatus::Deploying => $this->afterDeploy($update),
                AppUpdateStatus::Watching => $this->afterWatch($update),
                default => null,
            };

            // What runs now decides what is available next.
            if ($update->status->isTerminal()) {
                dispatch(new CheckAppUpdatesJob($update->service_instance_id));
            }
        });
    }

    private function afterBackups(AppUpdate $update): void
    {
        $runs = BackupRun::query()->whereIn('id', $update->backup_run_ids)->with('serviceInstance.service')->get();
        $failed = $runs->first(static fn (BackupRun $run): bool => $run->status === BackupRunStatus::Failed);

        if ($failed instanceof BackupRun) {
            $name = $failed->serviceInstance?->service->name ?? 'the data';
            $update->transitionTo(AppUpdateStatus::Failed, "The backup of {$name} failed, so launchroom didn’t update. {$update->from_version} keeps running.");

            return;
        }

        if ($runs->contains(static fn (BackupRun $run): bool => $run->status !== BackupRunStatus::Succeeded)) {
            if ($update->started_at->lessThan(now()->subMinutes(self::BACKUP_TIMEOUT_MINUTES))) {
                $update->transitionTo(AppUpdateStatus::Failed, "The backup took longer than 2 hours, so launchroom didn’t update. {$update->from_version} keeps running.");
            }

            return;
        }

        $this->apply($update);
    }

    /**
     * The image change and the deploy of this environment.
     */
    private function apply(AppUpdate $update): void
    {
        $instance = ServiceInstance::query()->whereKey($update->service_instance_id)->with(['service', 'environment.lastAppliedRevision'])->firstOrFail();
        $blocker = $this->plan->blocker($instance, running: $update);

        if ($blocker !== null) {
            $update->transitionTo(AppUpdateStatus::Failed, "{$blocker} Nothing changed.");

            return;
        }

        $user = $update->actor_id === null ? null : User::query()->find($update->actor_id);
        $actor = $user instanceof User ? AuditActor::user($user) : AuditActor::system();
        $name = $instance->service->name;

        try {
            $this->changeImage->handle($instance->service, new ChangeImageData($update->to_reference), $actor);
            $result = $this->deploy->handle($instance->environment, $user, $actor, "Update {$name} from {$update->from_version} to {$update->to_version}", DeploymentTrigger::Update);
        } catch (DomainRuleViolation $e) {
            $update->transitionTo(AppUpdateStatus::Failed, $e->getMessage());

            return;
        }

        $deployment = array_find($result->deployments, static fn (Deployment $deployment): bool => $deployment->service_instance_id === $instance->id);

        if (! $deployment instanceof Deployment) {
            $update->transitionTo(AppUpdateStatus::Failed, "The deploy didn’t include {$name}. {$update->from_version} keeps running.");

            return;
        }

        $update->forceFill(['deployment_id' => $deployment->id]);
        $update->transitionTo(AppUpdateStatus::Deploying);
    }

    private function afterDeploy(AppUpdate $update): void
    {
        $deployment = $update->deployment_id === null ? null : Deployment::query()->find($update->deployment_id);

        if (! $deployment instanceof Deployment) {
            $update->transitionTo(AppUpdateStatus::Failed, "The update’s deployment is gone. {$update->from_version} keeps running.");

            return;
        }

        match (true) {
            $deployment->status === DeploymentStatus::Active => $update->transitionTo($deployment->inProbation() ? AppUpdateStatus::Watching : AppUpdateStatus::Succeeded),
            in_array($deployment->status, [DeploymentStatus::Failed, DeploymentStatus::Cancelled], true) => $update->transitionTo(AppUpdateStatus::Failed, $this->failure($update, $deployment)),
            $deployment->status === DeploymentStatus::Superseded => $update->transitionTo(AppUpdateStatus::Failed, "Another deploy replaced the update before it went live. {$update->from_version} keeps running."),
            default => null,
        };
    }

    private function afterWatch(AppUpdate $update): void
    {
        $rollback = Deployment::query()
            ->where('service_instance_id', $update->service_instance_id)
            ->where('id', '>', $update->deployment_id ?? 0)
            ->whereNotNull('rollback_reason')
            ->oldest('id')
            ->first();

        if ($rollback instanceof Deployment) {
            $update->transitionTo(AppUpdateStatus::RolledBack, (string) $rollback->rollback_reason);

            return;
        }

        $deployment = $update->deployment_id === null ? null : Deployment::query()->find($update->deployment_id);

        if ($deployment instanceof Deployment && $deployment->inProbation()) {
            return;
        }

        $update->transitionTo(AppUpdateStatus::Succeeded);
    }

    private function failure(AppUpdate $update, Deployment $deployment): string
    {
        $reason = is_string($deployment->failure_message) && $deployment->failure_message !== ''
            ? rtrim(mb_substr(strtok($deployment->failure_message, "\n") ?: $deployment->failure_message, 0, 400), '.').'.'
            : 'The deployment didn’t finish.';

        return "{$update->to_version} didn’t go live: {$reason} {$update->from_version} keeps running.";
    }
}
