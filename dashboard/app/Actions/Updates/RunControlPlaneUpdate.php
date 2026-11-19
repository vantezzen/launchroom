<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Backups\RunControlPlaneBackup;
use App\Actions\Backups\StartControlPlaneBackup;
use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Enums\Updates\UpdateScope;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Server;
use App\Models\Updates\ControlPlaneUpdate;
use App\Services\Updates\SelfUpdateTasks;
use Illuminate\Support\Facades\DB;

/**
 * The queued part of a control-plane update (docs/11 §4, docs/14 §3):
 *
 * 1. with a control-plane backup destination and unless the owner chose no
 *    new backup (`skip_backup`): the pre-upgrade backup, run synchronously
 *    (as `launchroom:backups:control-plane --reason=pre_upgrade --wait`); if
 *    it fails, the update stops before anything changes. An update started
 *    with an existing backup (`backup_run_id`) uses it instead. A backup
 *    that is still running (chosen, or found in flight) is waited for: the
 *    update stays `backing_up` and {@see AdvanceUpdates} runs this again
 *    once the backup has finished;
 * 2. the self_update task (scope control_plane) pinned to the control-plane
 *    server. Its launchroomd verifies the signed bundle, snapshots the
 *    database, installs the release (migrations run in the dashboard
 *    entrypoint) and rolls back on failure. {@see AdvanceUpdates} records
 *    the outcome when the task finishes.
 *
 * Idempotent: only a pending update is started, and a backing-up or
 * updating one only continues until its self_update task exists, so
 * {@see AdvanceUpdates} may dispatch it again whenever its job may have died
 * (docs/11 §4.3). A pre-upgrade backup whose worker died
 * ({@see BackupRun::ABANDONED}) is started again up to
 * `launchroom.updates.backup_restarts` times; nothing has changed yet.
 */
final readonly class RunControlPlaneUpdate
{
    public function __construct(
        private StartControlPlaneBackup $startBackup,
        private RunControlPlaneBackup $runBackup,
        private SelfUpdateTasks $tasks,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ControlPlaneUpdate $update): ControlPlaneUpdate
    {
        $claimed = DB::transaction(function () use ($update): ?ControlPlaneUpdate {
            $locked = ControlPlaneUpdate::query()->whereKey($update->id)->lockForUpdate()->first();

            if (! $locked instanceof ControlPlaneUpdate) {
                return null;
            }

            // Waiting for a backup, or its job died before the task
            // existed: continue.
            if ($locked->status === ControlPlaneUpdateStatus::BackingUp || $locked->status === ControlPlaneUpdateStatus::Updating) {
                return $locked->daemon_task_id === null && config()->string('launchroom.version') === $locked->from_version ? $locked : null;
            }

            if ($locked->status !== ControlPlaneUpdateStatus::Pending) {
                return null;
            }

            $locked->transitionTo(! $locked->skip_backup && BackupDestination::query()->instanceWide()->exists()
                ? ControlPlaneUpdateStatus::BackingUp
                : ControlPlaneUpdateStatus::Updating);

            return $locked;
        });

        if (! $claimed instanceof ControlPlaneUpdate) {
            return $update->refresh();
        }

        if ($claimed->status === ControlPlaneUpdateStatus::BackingUp && $this->backUp($claimed) !== true) {
            return $claimed;
        }

        $server = Server::query()
            ->whereKey($claimed->server_id)
            ->controlPlane()
            ->first();

        if (! $server instanceof Server) {
            $this->fail($claimed, 'The control-plane server is not enrolled.');

            return $claimed;
        }

        return DB::transaction(function () use ($claimed, $server): ControlPlaneUpdate {
            // Another run of this job may have got here first.
            $locked = ControlPlaneUpdate::query()->whereKey($claimed->id)->lockForUpdate()->first();

            if (! $locked instanceof ControlPlaneUpdate || $locked->daemon_task_id !== null || ! in_array($locked->status, [ControlPlaneUpdateStatus::BackingUp, ControlPlaneUpdateStatus::Updating], true)) {
                return $locked ?? $claimed;
            }

            $task = $this->tasks->create($server, $locked->to_version, $locked->daemon_image, UpdateScope::ControlPlane);
            $locked->forceFill(['daemon_task_id' => $task->id]);

            if ($locked->status === ControlPlaneUpdateStatus::BackingUp) {
                $locked->transitionTo(ControlPlaneUpdateStatus::Updating);
            } else {
                $locked->save();
            }

            return $locked;
        });
    }

    /**
     * True when the backup is there, false when the update failed, null
     * while it waits for a backup that is still running.
     */
    private function backUp(ControlPlaneUpdate $update): ?bool
    {
        $destination = BackupDestination::query()->instanceWide()->first();
        $run = $update->backup_run_id === null ? null : BackupRun::query()->find($update->backup_run_id);

        // The chosen backup is gone or on another destination now: take a new one.
        if ($run instanceof BackupRun && $run->destination_id !== $destination?->id) {
            $run = null;
        }

        // Its worker died (docs/11 §4.3): take it again, a few times.
        if ($run instanceof BackupRun && $run->status === BackupRunStatus::Failed && $run->error === BackupRun::ABANDONED) {
            if ($update->backup_attempts >= config()->integer('launchroom.updates.backup_restarts')) {
                $this->fail($update, 'The pre-upgrade backup stopped without finishing '.($update->backup_attempts + 1).' times because the background worker running it stopped, so nothing was changed. Check Settings → System, then try again.');

                return false;
            }

            $update->forceFill(['backup_run_id' => null, 'backup_attempts' => $update->backup_attempts + 1])->save();
            $run = null;
        }

        if (! $run instanceof BackupRun) {
            try {
                $run = $this->startBackup->handle(BackupReason::PreUpgrade, $update->actor, AuditActor::system(), dispatch: false);
            } catch (DomainRuleViolation $e) {
                $this->fail($update, 'Pre-upgrade backup not possible: '.$e->getMessage());

                return false;
            }

            $update->forceFill(['backup_run_id' => $run->id])->save();
        }

        // Not dispatched: this job takes a run nobody has started yet (its
        // own, or a queued one: the claim is atomic). A run already going in
        // another job is waited for.
        if ($run->status === BackupRunStatus::Pending) {
            $run = $this->runBackup->handle($run);
        }

        if (! $run->status->isTerminal()) {
            return null;
        }

        if ($run->status !== BackupRunStatus::Succeeded) {
            $this->fail($update, 'The pre-upgrade backup failed, so nothing was changed: '.($run->error ?? 'unknown error'));

            return false;
        }

        return true;
    }

    /**
     * Fails the update unless it moved on meanwhile (cancelled by the owner
     * while the backup ran, or another run of this job got further).
     */
    private function fail(ControlPlaneUpdate $update, string $error): void
    {
        $failed = DB::transaction(static function () use ($update, $error): ?ControlPlaneUpdate {
            $locked = ControlPlaneUpdate::query()->whereKey($update->id)->lockForUpdate()->first();

            if (! $locked instanceof ControlPlaneUpdate || $locked->status->isTerminal() || $locked->daemon_task_id !== null) {
                return null;
            }

            $locked->transitionTo(ControlPlaneUpdateStatus::Failed, $error);

            return $locked;
        });

        if ($failed instanceof ControlPlaneUpdate) {
            $update->setRawAttributes($failed->getAttributes(), true);
            $this->audit->handle($failed->finishedAuditEntry());
        }
    }
}
