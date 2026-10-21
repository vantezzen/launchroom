<?php

declare(strict_types=1);

namespace App\Jobs\Backups;

use App\Actions\Backups\SyncBackupTasks;
use App\Enums\DaemonTaskType;
use App\Models\DaemonTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Applies a completed backup/restore/snapshot task to its run right away
 * (the scheduler tick is the safety net).
 */
#[Backoff([5, 30])]
#[Timeout(60)]
#[Tries(3)]
final class SyncBackupTaskJob implements ShouldQueue
{
    use Queueable;

    /** Task types whose completion this job handles. */
    public const array TYPES = [DaemonTaskType::Backup, DaemonTaskType::Restore, DaemonTaskType::BackupSnapshots, DaemonTaskType::BackupMaintenance, DaemonTaskType::VerifyRestore];

    public function __construct(
        public readonly int $taskId,
    ) {
        $this->afterCommit();
    }

    /**
     * Dispatches the sync for backup task types; a no-op for others.
     */
    public static function dispatchFor(DaemonTask $task): void
    {
        if (in_array($task->type, self::TYPES, true)) {
            dispatch(new self($task->id));
        }
    }

    public function handle(SyncBackupTasks $sync): void
    {
        $task = DaemonTask::query()->whereKey($this->taskId)->first();

        if ($task instanceof DaemonTask) {
            $sync->handle($task);
        }
    }
}
