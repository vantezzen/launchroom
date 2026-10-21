<?php

declare(strict_types=1);

namespace App\Listeners\Backups;

use App\Events\DaemonTaskFinished;
use App\Jobs\Backups\SyncBackupTaskJob;
use App\Models\DaemonTask;

/**
 * A backup, restore or snapshot-listing task finished: update its run now
 * instead of waiting for the next scheduler tick.
 */
final class SyncBackupRunsOnTaskFinished
{
    public function handle(DaemonTaskFinished $event): void
    {
        $task = DaemonTask::query()->whereKey($event->taskId)->first(['id', 'type']);

        if ($task instanceof DaemonTask) {
            SyncBackupTaskJob::dispatchFor($task);
        }
    }
}
