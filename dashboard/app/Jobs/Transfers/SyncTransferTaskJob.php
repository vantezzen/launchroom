<?php

declare(strict_types=1);

namespace App\Jobs\Transfers;

use App\Actions\Transfers\AdvanceImports;
use App\Actions\Transfers\SyncTransferTasks;
use App\Models\DaemonTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Applies a finished import/export task to its run right away and moves the
 * other imports on. The transfers tick is the safety net.
 */
#[Backoff([5, 30])]
#[Timeout(60)]
#[Tries(3)]
final class SyncTransferTaskJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $taskId,
    ) {
        $this->afterCommit();
    }

    public function handle(SyncTransferTasks $sync, AdvanceImports $advance): void
    {
        $task = DaemonTask::query()->whereKey($this->taskId)->first();

        if ($task instanceof DaemonTask) {
            $sync->handle($task);
        }

        $advance->handle();
    }
}
