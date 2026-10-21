<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\Actions\Backups\RunControlPlaneBackup;
use App\Actions\Backups\StartControlPlaneBackup;
use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\ServerStatus;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Enums\Updates\UpdateScope;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Server;
use App\Models\Updates\ControlPlaneUpdate;
use App\Services\Updates\SelfUpdateTasks;
use App\Services\Updates\UpdateAudit;
use Illuminate\Support\Facades\DB;

/**
 * The queued part of a control-plane update (docs/11 §4, docs/14 §3):
 *
 * 1. with a control-plane backup destination: the pre-upgrade backup, run
 *    synchronously (as `launchroom:backups:control-plane --reason=pre_upgrade
 *    --wait`); if it fails, the update stops before anything changes;
 * 2. the self_update task (scope control_plane) pinned to the control-plane
 *    server. Its launchroomd verifies the signed bundle, snapshots the
 *    database, installs the release (migrations run in the dashboard
 *    entrypoint) and rolls back on failure. {@see AdvanceUpdates} records
 *    the outcome when the task finishes.
 *
 * Idempotent: only a pending update is started.
 */
final readonly class RunControlPlaneUpdate
{
    public function __construct(
        private StartControlPlaneBackup $startBackup,
        private RunControlPlaneBackup $runBackup,
        private SelfUpdateTasks $tasks,
        private UpdateAudit $audit,
    ) {}

    public function handle(ControlPlaneUpdate $update): ControlPlaneUpdate
    {
        $claimed = DB::transaction(function () use ($update): ?ControlPlaneUpdate {
            $locked = ControlPlaneUpdate::query()->whereKey($update->id)->lockForUpdate()->first();

            if (! $locked instanceof ControlPlaneUpdate || $locked->status !== ControlPlaneUpdateStatus::Pending) {
                return null;
            }

            $locked->transitionTo(BackupDestination::query()->whereNull('team_id')->exists()
                ? ControlPlaneUpdateStatus::BackingUp
                : ControlPlaneUpdateStatus::Updating);

            return $locked;
        });

        if (! $claimed instanceof ControlPlaneUpdate) {
            return $update->refresh();
        }

        if ($claimed->status === ControlPlaneUpdateStatus::BackingUp && ! $this->backUp($claimed)) {
            return $claimed;
        }

        $server = Server::query()
            ->whereKey($claimed->server_id)
            ->where('is_control_plane', true)
            ->where('status', '!=', ServerStatus::Revoked->value)
            ->first();

        if (! $server instanceof Server) {
            $this->fail($claimed, 'The control-plane server is not enrolled.');

            return $claimed;
        }

        DB::transaction(function () use ($claimed, $server): void {
            $task = $this->tasks->create($server, $claimed->to_version, $claimed->daemon_image, UpdateScope::ControlPlane);
            $claimed->forceFill(['daemon_task_id' => $task->id]);

            if ($claimed->status === ControlPlaneUpdateStatus::BackingUp) {
                $claimed->transitionTo(ControlPlaneUpdateStatus::Updating);
            } else {
                $claimed->save();
            }
        });

        return $claimed;
    }

    private function backUp(ControlPlaneUpdate $update): bool
    {
        try {
            $run = $this->startBackup->handle(BackupReason::PreUpgrade, $update->actor, AuditActor::system(), dispatch: false);
        } catch (DomainRuleViolation $e) {
            $this->fail($update, 'Pre-upgrade backup not possible: '.$e->getMessage());

            return false;
        }

        $update->forceFill(['backup_run_id' => $run->id])->save();

        if ($run->status !== BackupRunStatus::Succeeded) {
            $run = $this->runBackup->handle($run);
        }

        if ($run->status === BackupRunStatus::Running) {
            $this->fail($update, 'Another control-plane backup is running. Start the update again when it has finished.');

            return false;
        }

        if ($run->status !== BackupRunStatus::Succeeded) {
            $this->fail($update, 'The pre-upgrade backup failed, so nothing was changed: '.($run->error ?? 'unknown error'));

            return false;
        }

        return true;
    }

    private function fail(ControlPlaneUpdate $update, string $error): void
    {
        $update->transitionTo(ControlPlaneUpdateStatus::Failed, $error);
        $this->audit->controlPlaneFinished($update);
    }
}
