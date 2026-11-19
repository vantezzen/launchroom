<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Backups\BackupVerificationCheckData;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\DestinationErrorKind;
use App\Enums\Backups\DestinationStatus;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\Backups\VerificationStatus;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\HoldKind;
use App\Events\Backups\BackupRunFinished;
use App\Events\Backups\RestoreRunFinished;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupVerification;
use App\Models\Backups\RestoreRun;
use App\Models\DaemonTask;
use App\Services\Backups\SnapshotCache;
use App\Services\Holds\Holds;
use App\Services\Holds\RestoreHolds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors daemon task outcomes onto backup and restore runs (docs/14 §7).
 *
 * Called for one task right after a daemon completes it, and for every
 * in-flight run by the scheduler tick (which also catches tasks that ended
 * without a completion, e.g. an expired lease). Idempotent: terminal runs
 * never change again.
 */
final readonly class SyncBackupTasks
{
    public function __construct(
        private SnapshotCache $snapshots,
    ) {}

    public function handle(?DaemonTask $task = null): void
    {
        if ($task instanceof DaemonTask) {
            $this->sync($task);

            return;
        }

        $taskIds = [
            ...BackupRun::query()->whereIn('status', BackupRunStatus::nonTerminal())->whereNotNull('daemon_task_id')->pluck('daemon_task_id')->all(),
            ...RestoreRun::query()->where('status', RestoreRunStatus::Restoring->value)->whereNotNull('daemon_task_id')->pluck('daemon_task_id')->all(),
            ...BackupVerification::query()->whereIn('status', VerificationStatus::nonTerminal())->whereNotNull('daemon_task_id')->pluck('daemon_task_id')->all(),
        ];

        foreach (DaemonTask::query()->whereIn('id', $taskIds)->get() as $daemonTask) {
            $this->sync($daemonTask);
        }
    }

    private function sync(DaemonTask $task): void
    {
        match ($task->type) {
            DaemonTaskType::Backup => $this->backup($task),
            DaemonTaskType::Restore => $this->restore($task),
            DaemonTaskType::BackupSnapshots => $this->snapshots->storeFromTask($task),
            DaemonTaskType::VerifyRestore => $this->verification($task),
            default => null,
        };
    }

    private function backup(DaemonTask $task): void
    {
        DB::transaction(function () use ($task): void {
            $run = BackupRun::query()->where('daemon_task_id', $task->id)->lockForUpdate()->first();

            if (! $run instanceof BackupRun || $run->status->isTerminal()) {
                return;
            }

            if ($task->status === DaemonTaskStatus::Leased && $run->status === BackupRunStatus::Pending) {
                $run->transitionTo(BackupRunStatus::Running);

                return;
            }

            if ($task->status === DaemonTaskStatus::Succeeded) {
                $result = $task->result?->backup;

                if ($result === null) {
                    $run->transitionTo(BackupRunStatus::Failed, 'The server reported success without a snapshot id.');
                    event(new BackupRunFinished($run->id, $run->team_id));

                    return;
                }

                $run->forceFill([
                    'snapshot_id' => $result->snapshotId,
                    'size_bytes' => $result->sizeBytes,
                    'added_bytes' => is_int($result->addedBytes) ? $result->addedBytes : null,
                    'duration_ms' => $result->durationMs,
                ]);
                $run->transitionTo(BackupRunStatus::Succeeded);
                event(new BackupRunFinished($run->id, $run->team_id));
                $run->destination()->first()?->markChecked(DestinationStatus::Ok);

                return;
            }

            if ($task->status === DaemonTaskStatus::Failed || $task->status === DaemonTaskStatus::Cancelled) {
                $run->transitionTo(BackupRunStatus::Failed, $task->error ?? 'The backup task '.$task->status->value.'.');
                event(new BackupRunFinished($run->id, $run->team_id));

                // The storage refused it (key, repository password, bucket): its card says so too.
                if (in_array(DestinationErrorKind::classify($task->error), [DestinationErrorKind::WrongCredentials, DestinationErrorKind::WrongRepositoryPassword, DestinationErrorKind::BucketMissing], true)) {
                    $run->destination()->first()?->markChecked(DestinationStatus::Failing, $task->error);
                }
            }
        });
    }

    private function restore(DaemonTask $task): void
    {
        DB::transaction(function () use ($task): void {
            $run = RestoreRun::query()->where('daemon_task_id', $task->id)->lockForUpdate()->first();

            if (! $run instanceof RestoreRun || $run->status !== RestoreRunStatus::Restoring) {
                return;
            }

            // Held while it ran (volume: the service; database in place: its dependents).
            $held = RestoreHolds::heldBy($run);

            if ($task->status === DaemonTaskStatus::Succeeded) {
                $result = $task->result?->restore;
                $run->forceFill([
                    'size_bytes' => $result?->sizeBytes,
                    'restored_to' => is_string($result?->restoredTo) ? CarbonImmutable::parse($result->restoredTo) : null,
                ]);
                $run->transitionTo(RestoreRunStatus::Succeeded);
            } elseif ($task->status === DaemonTaskStatus::Failed || $task->status === DaemonTaskStatus::Cancelled) {
                $run->transitionTo(RestoreRunStatus::Failed, $task->error ?? 'The restore task '.$task->status->value.'.');
            } else {
                return;
            }

            event(new RestoreRunFinished($run->id, $run->team_id));

            // A finished restore releases the held services (and their queued deployments).
            $volumeServer = ($run->subject === BackupSubject::Volume || $run->subject === BackupSubject::BaseBackup) && $task->server_id !== null ? [$task->server_id] : [];
            Holds::changed(HoldKind::Restore, $held, $volumeServer);
        });
    }

    private function verification(DaemonTask $task): void
    {
        DB::transaction(function () use ($task): void {
            $verification = BackupVerification::query()->where('daemon_task_id', $task->id)->lockForUpdate()->first();

            if (! $verification instanceof BackupVerification || $verification->status->isTerminal()) {
                return;
            }

            if ($task->status === DaemonTaskStatus::Leased && $verification->status === VerificationStatus::Pending) {
                $verification->transitionTo(VerificationStatus::Running);

                return;
            }

            if ($task->status === DaemonTaskStatus::Succeeded) {
                $result = $task->result?->verify;
                $checks = [];

                foreach ($result->checks ?? [] as $check) {
                    $checks[] = new BackupVerificationCheckData($check['name'], $check['passed'], isset($check['detail']) ? mb_substr($check['detail'], 0, 512) : null);
                }

                $int = static fn (mixed $value): ?int => is_int($value) ? $value : null;
                $verification->forceFill([
                    'checks' => $checks,
                    'tables' => $int($result?->tables),
                    'rows' => $int($result?->rows),
                    'files' => $int($result?->files),
                    'bytes' => $int($result?->bytes),
                    'duration_ms' => $result?->durationMs,
                    'restored_to' => is_string($result?->restoredTo) ? CarbonImmutable::parse($result->restoredTo) : null,
                ]);
                $passed = $result?->passed === true;
                $failed = array_values(array_filter($checks, static fn (BackupVerificationCheckData $check): bool => ! $check->passed));
                $verification->transitionTo(
                    $passed ? VerificationStatus::Passed : VerificationStatus::Failed,
                    $passed ? null : (isset($failed[0]) ? $failed[0]->name.': '.($failed[0]->detail ?? 'failed') : 'A check failed.'),
                );

                return;
            }

            if ($task->status === DaemonTaskStatus::Failed || $task->status === DaemonTaskStatus::Cancelled) {
                $verification->transitionTo(VerificationStatus::Failed, $task->error ?? 'The verification task '.$task->status->value.'.');
            }
        });
    }
}
