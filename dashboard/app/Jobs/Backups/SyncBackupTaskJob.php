<?php

declare(strict_types=1);

namespace App\Jobs\Backups;

use App\Actions\Backups\SyncBackupTasks;
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

    public function __construct(
        public readonly int $taskId,
    ) {
        $this->afterCommit();
    }

    public function handle(SyncBackupTasks $sync): void
    {
        $task = DaemonTask::query()->whereKey($this->taskId)->first();

        if ($task instanceof DaemonTask) {
            $sync->handle($task);
        }
    }
}
