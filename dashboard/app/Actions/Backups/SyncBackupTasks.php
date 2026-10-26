<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\DestinationStatus;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\Backups\VerificationStatus;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Events\NodeStateInvalidated;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupVerification;
use App\Models\Backups\RestoreRun;
use App\Models\DaemonTask;
use App\Services\Backups\DataHolds;
use App\Services\Backups\RestoreHolds;
use App\Services\Backups\SnapshotCache;
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
            ...BackupRun::query()->whereIn('status', [BackupRunStatus::Pending->value, BackupRunStatus::Running->value])->whereNotNull('daemon_task_id')->pluck('daemon_task_id')->all(),
            ...RestoreRun::query()->where('status', RestoreRunStatus::Restoring->value)->whereNotNull('daemon_task_id')->pluck('daemon_task_id')->all(),
            ...BackupVerification::query()->whereIn('status', [VerificationStatus::Pending->value, VerificationStatus::Running->value])->whereNotNull('daemon_task_id')->pluck('daemon_task_id')->all(),
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
                $result = is_array($task->result['backup'] ?? null) ? $task->result['backup'] : [];
                $snapshot = is_string($result['snapshotId'] ?? null) ? $result['snapshotId'] : null;

                if ($snapshot === null) {
                    $run->transitionTo(BackupRunStatus::Failed, 'The server reported success without a snapshot id.');

                    return;
                }

                $run->forceFill([
                    'snapshot_id' => $snapshot,
                    'size_bytes' => is_int($result['sizeBytes'] ?? null) ? $result['sizeBytes'] : null,
                    'added_bytes' => is_int($result['addedBytes'] ?? null) ? $result['addedBytes'] : null,
                    'duration_ms' => is_int($result['durationMs'] ?? null) ? $result['durationMs'] : null,
                ]);
                $run->transitionTo(BackupRunStatus::Succeeded);
                $run->destination()->first()?->markChecked(DestinationStatus::Ok);

                return;
            }

            if ($task->status === DaemonTaskStatus::Failed || $task->status === DaemonTaskStatus::Cancelled) {
                $run->transitionTo(BackupRunStatus::Failed, $task->error ?? 'The backup task '.$task->status->value.'.');
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
                $result = is_array($task->result['restore'] ?? null) ? $task->result['restore'] : [];
                $run->forceFill([
                    'size_bytes' => is_int($result['sizeBytes'] ?? null) ? $result['sizeBytes'] : null,
                    'restored_to' => is_string($result['restoredTo'] ?? null) ? CarbonImmutable::parse($result['restoredTo']) : null,
                ]);
                $run->transitionTo(RestoreRunStatus::Succeeded);
            } elseif ($task->status === DaemonTaskStatus::Failed || $task->status === DaemonTaskStatus::Cancelled) {
                $run->transitionTo(RestoreRunStatus::Failed, $task->error ?? 'The restore task '.$task->status->value.'.');
            } else {
                return;
            }

            // A finished restore releases the held services (and their queued deployments).
            DataHolds::advanceDeployments($held);
            $servers = RestoreHolds::serverIds($held);

            if (($run->subject === BackupSubject::Volume || $run->subject === BackupSubject::BaseBackup) && $task->server_id !== null) {
                $servers[] = $task->server_id;
            }

            if ($servers !== []) {
                event(new NodeStateInvalidated(array_values(array_unique($servers))));
            }
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
                $result = is_array($task->result['verify'] ?? null) ? $task->result['verify'] : [];
                $checks = [];

                foreach (is_array($result['checks'] ?? null) ? $result['checks'] : [] as $check) {
                    if (is_array($check) && is_string($check['name'] ?? null)) {
                        $checks[] = array_filter([
                            'name' => $check['name'],
                            'passed' => ($check['passed'] ?? false) === true,
                            'detail' => is_string($check['detail'] ?? null) ? mb_substr($check['detail'], 0, 512) : null,
                        ], static fn (mixed $value): bool => $value !== null);
                    }
                }

                $int = static fn (string $key): ?int => is_int($result[$key] ?? null) ? $result[$key] : null;
                $verification->forceFill([
                    'checks' => $checks,
                    'tables' => $int('tables'),
                    'rows' => $int('rows'),
                    'files' => $int('files'),
                    'bytes' => $int('bytes'),
                    'duration_ms' => $int('durationMs'),
                    'restored_to' => is_string($result['restoredTo'] ?? null) ? CarbonImmutable::parse($result['restoredTo']) : null,
                ]);
                $passed = ($result['passed'] ?? false) === true;
                $failed = array_values(array_filter($checks, static fn (array $check): bool => ! $check['passed']));
                $verification->transitionTo(
                    $passed ? VerificationStatus::Passed : VerificationStatus::Failed,
                    $passed ? null : (isset($failed[0]) ? $failed[0]['name'].': '.($failed[0]['detail'] ?? 'failed') : 'A check failed.'),
                );

                return;
            }

            if ($task->status === DaemonTaskStatus::Failed || $task->status === DaemonTaskStatus::Cancelled) {
                $verification->transitionTo(VerificationStatus::Failed, $task->error ?? 'The verification task '.$task->status->value.'.');
            }
        });
    }
}
