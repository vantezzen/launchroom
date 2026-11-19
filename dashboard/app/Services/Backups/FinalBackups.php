<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\DeletionRequestStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\DeletionRequest;
use App\Models\ServiceInstance;
use App\Models\Volume;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Final backups before data goes away (docs/14 §9.13). Deleting an
 * environment, a project or a preview, or deploying a staged service
 * removal, first backs up every database (dump) and every other service's
 * volumes (snapshots) that have backups configured (an enabled schedule or a
 * successful backup) and aren't turned off, while their workloads still run. The deletion waits
 * for them; if one fails (or can't start, e.g. a database that isn't
 * running), the deletion is blocked with the reason until an owner/admin
 * retries it or chooses "delete without final backup" (authorized,
 * audited). Callers own authorization and their transaction.
 *
 * The state lives in a DeletionRequest per subject, opened by
 * RequestFinalBackups. This class reads and refreshes it.
 */
final readonly class FinalBackups
{
    /**
     * @return Collection<int, ServiceInstance>
     */
    public function instancesFor(DeletionRequest $request): Collection
    {
        $query = ServiceInstance::query()->where('team_id', $request->team_id)->with(['service', 'volumes'])->orderBy('id');

        return match ($request->subject_type) {
            DeletionRequest::ENVIRONMENT => $query->where('environment_id', $request->subject_id)->get(),
            DeletionRequest::PROJECT => $query->whereHas('environment', static fn (Builder $environment) => $environment->where('project_id', $request->subject_id))->get(),
            default => $query->whereKey($request->subject_id)->get(),
        };
    }

    /**
     * Instances whose data has backups configured and exists (a deployed
     * database, volumes placed on a server).
     *
     * @param  Collection<int, ServiceInstance>  $instances
     * @return list<ServiceInstance>
     */
    public function needed(Collection $instances): array
    {
        if ($instances->isEmpty()) {
            return [];
        }

        $instances->loadMissing(['service', 'volumes']);
        $ids = $instances->modelKeys();
        $configured = [];

        foreach ([
            ...BackupSchedule::query()->whereIn('service_instance_id', $ids)->where('enabled', true)->pluck('service_instance_id')->all(),
            ...BackupRun::query()->whereIn('service_instance_id', $ids)->where('status', BackupRunStatus::Succeeded->value)->distinct()->pluck('service_instance_id')->all(),
        ] as $id) {
            $configured[is_numeric($id) ? (int) $id : 0] = true;
        }

        $needed = [];

        foreach ($instances as $instance) {
            $subject = BackupSubjects::for($instance);
            $exists = match ($subject) {
                BackupSubject::Database => $instance->active_deployment_id !== null,
                // Volumes whose backups are turned off get no final backup.
                BackupSubject::Volume => BackupSubjects::backedUpVolumes($instance) !== [],
                default => false,
            };

            if ($exists && isset($configured[$instance->id]) && BackupDestination::defaultFor($instance->team_id) instanceof BackupDestination) {
                $needed[] = $instance;
            }
        }

        return $needed;
    }

    /**
     * Moves a backing_up request on: ready when every run succeeded, blocked
     * when one failed (or the runs took longer than allowed).
     */
    public function refresh(DeletionRequest $request): void
    {
        if ($request->status !== DeletionRequestStatus::BackingUp) {
            return;
        }

        $runs = $request->backupRuns();

        if ($runs->count() !== count($request->backup_run_ids)) {
            $request->transitionTo(DeletionRequestStatus::Blocked, 'A final backup record is missing. Retry the final backup.');

            return;
        }

        $failed = $runs->filter(static fn (BackupRun $run): bool => $run->status === BackupRunStatus::Failed);

        if ($failed->isNotEmpty()) {
            $request->transitionTo(DeletionRequestStatus::Blocked, $this->describe($failed));

            return;
        }

        if ($runs->every(static fn (BackupRun $run): bool => $run->status === BackupRunStatus::Succeeded)) {
            $request->transitionTo(DeletionRequestStatus::Ready);

            return;
        }

        // updated_at: when it (re)entered backing_up.
        if ($request->updated_at !== null && $request->updated_at->lt(now()->subMinutes(config()->integer('launchroom.backups.final_backup_timeout_minutes')))) {
            $request->transitionTo(DeletionRequestStatus::Blocked, 'The final backup did not finish in time. Retry it, or delete without a final backup.');
        }
    }

    /**
     * Before a Deploy tears down a staged service removal: refuses while its
     * final backup runs or after it failed; returns the open request (the
     * caller completes it after the teardown) or null.
     *
     * @throws DomainRuleViolation
     */
    public function assertCleared(ServiceInstance $instance): ?DeletionRequest
    {
        $request = DeletionRequest::openFor(DeletionRequest::SERVICE_INSTANCE, $instance->id, lock: true);

        if (! $request instanceof DeletionRequest) {
            return null;
        }

        $this->refresh($request);

        return match ($request->status) {
            DeletionRequestStatus::BackingUp => throw DomainRuleViolation::on('environment', "The final backup of {$request->subject_name} is still running. Deploy again once it finished."),
            DeletionRequestStatus::Blocked => throw DomainRuleViolation::on('environment', "{$request->subject_name} can’t be removed yet: {$request->error} Retry the final backup or remove it without one (its Backups settings), or discard the removal."),
            default => $request,
        };
    }

    /**
     * The final snapshot per volume (a database's dump covers its volume)
     * of a request whose backups succeeded, for VolumeRetirements.
     *
     * @return array<int, string> volume id => restic snapshot id
     */
    public function runsByVolume(?DeletionRequest $request): array
    {
        if (! $request instanceof DeletionRequest || $request->status === DeletionRequestStatus::Overridden) {
            return [];
        }

        $out = [];

        foreach ($request->backupRuns() as $run) {
            if ($run->status !== BackupRunStatus::Succeeded || $run->snapshot_id === null) {
                continue;
            }

            if ($run->volume_id !== null) {
                $out[$run->volume_id] = $run->snapshot_id;

                continue;
            }

            foreach (Volume::query()->where('service_instance_id', $run->service_instance_id)->pluck('id') as $volumeId) {
                $out[is_numeric($volumeId) ? (int) $volumeId : 0] = $run->snapshot_id;
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, BackupRun>  $failed
     */
    private function describe(Collection $failed): string
    {
        $parts = $failed->map(static function (BackupRun $run): string {
            $name = $run->serviceInstance?->service->name ?? 'a service';
            $what = $run->volume !== null ? " (volume {$run->volume->mount_path})" : '';

            return "{$name}{$what}: ".($run->error ?? 'failed');
        })->all();

        return 'The final backup failed for '.implode('; ', $parts);
    }
}
