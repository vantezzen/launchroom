<?php

declare(strict_types=1);

namespace App\Listeners\Updates;

use App\Enums\DaemonTaskType;
use App\Events\DaemonTaskFinished;
use App\Jobs\Updates\AdvanceUpdatesJob;
use App\Models\DaemonTask;

/**
 * A self_update task finished: record it and start the next server now
 * instead of waiting for the scheduler.
 */
final class AdvanceUpdatesOnTaskFinished
{
    public function handle(DaemonTaskFinished $event): void
    {
        $type = DaemonTask::query()->whereKey($event->taskId)->value('type');

        if ($type === DaemonTaskType::SelfUpdate || $type === DaemonTaskType::SelfUpdate->value) {
            dispatch(new AdvanceUpdatesJob);
        }
    }
}
