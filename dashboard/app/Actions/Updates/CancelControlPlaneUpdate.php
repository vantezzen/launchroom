<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\DaemonTaskStatus;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Models\Backups\BackupRun;
use App\Models\DaemonTask;
use App\Models\Updates\ControlPlaneUpdate;
use App\Support\Refusal;
use Illuminate\Support\Facades\DB;

/**
 * "Cancel update" (docs/11 §4.3): stops a control-plane update before
 * launchroomd took its self_update task, so nothing has changed yet. Works
 * whatever the update was waiting for (a queued job, a backup, a server
 * that doesn't pick the task up), and needs no worker: it is a row change.
 *
 * - a self_update task that is still pending is withdrawn (cancelled);
 * - the pre-upgrade backup keeps running when it is alive (it is an
 *   ordinary backup then); one without a sign of life for the stall time
 *   is given up as abandoned, so the next attempt doesn't wait for it;
 * - the update ends `cancelled` with `$note`.
 *
 * Refused once launchroomd has taken the task: it is installing, and goes
 * back to the old version by itself when the new one doesn't start.
 */
final readonly class CancelControlPlaneUpdate
{
    public const string CANCELLED = 'Cancelled before anything changed.';

    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ControlPlaneUpdate $update, AuditActor $actor, string $note = self::CANCELLED): ControlPlaneUpdate|Refusal
    {
        $result = DB::transaction(function () use ($update, $note): ControlPlaneUpdate|Refusal {
            $locked = ControlPlaneUpdate::query()->whereKey($update->id)->lockForUpdate()->first();

            if (! $locked instanceof ControlPlaneUpdate || $locked->status->isTerminal()) {
                return Refusal::conflict('This update has already finished.');
            }

            $task = $locked->daemon_task_id === null ? null : DaemonTask::query()->whereKey($locked->daemon_task_id)->lockForUpdate()->first();
            $locked->setRelation('daemonTask', $task);

            if (! $locked->cancellable()) {
                return Refusal::conflict('launchroom is already installing the update, so it can’t be stopped now. If the new version doesn’t start, it goes back to the old one by itself.');
            }

            if ($task instanceof DaemonTask && $task->status === DaemonTaskStatus::Pending) {
                $task->forceFill(['error' => 'The update was cancelled.']);
                $task->transitionTo(DaemonTaskStatus::Cancelled);
            }

            $this->giveUpSilentBackup($locked);
            $locked->transitionTo(ControlPlaneUpdateStatus::Cancelled, $note);

            return $locked;
        });

        if ($result instanceof ControlPlaneUpdate) {
            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ControlPlaneUpdateFinished,
                actor: $actor,
                subjectType: 'control_plane_update',
                subjectId: $result->public_id,
                meta: ['from' => $result->from_version, 'to' => $result->to_version, 'status' => $result->status->value],
            ));
        }

        return $result;
    }

    private function giveUpSilentBackup(ControlPlaneUpdate $update): void
    {
        if ($update->backup_run_id === null) {
            return;
        }

        $run = BackupRun::query()->whereKey($update->backup_run_id)->lockForUpdate()->first();

        if (! $run instanceof BackupRun || $run->status !== BackupRunStatus::Running || $run->team_id !== null) {
            return;
        }

        $last = $run->heartbeat_at ?? $run->started_at ?? $run->created_at;

        if ($last?->lt(now()->subSeconds(config()->integer('launchroom.updates.stall_seconds'))) ?? false) {
            $run->transitionTo(BackupRunStatus::Failed, BackupRun::ABANDONED);
        }
    }
}
