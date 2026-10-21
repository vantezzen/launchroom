<?php

declare(strict_types=1);

namespace App\Listeners\Transfers;

use App\Events\DaemonTaskFinished;
use App\Jobs\Transfers\SyncTransferTaskJob;
use App\Models\DaemonTask;

/**
 * An import or export task (or a safety snapshot) finished: update its run
 * now instead of waiting for the next tick (docs/14 §12.5).
 */
final class SyncTransfersOnTaskFinished
{
    public function handle(DaemonTaskFinished $event): void
    {
        $task = DaemonTask::query()->whereKey($event->taskId)->first(['id', 'type']);

        if ($task instanceof DaemonTask) {
            SyncTransferTaskJob::dispatchFor($task);
        }
    }
}
