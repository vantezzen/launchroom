<?php

declare(strict_types=1);

namespace App\Listeners\Previews;

use App\Enums\Previews\PreviewStatus;
use App\Events\DaemonTaskFinished;
use App\Jobs\Previews\AdvancePreviewsJob;
use App\Models\DaemonTask;
use App\Models\Previews\Preview;

/**
 * A restore or sanitize task finished: previews preparing their data in
 * that team continue right away instead of at the next tick.
 */
final class AdvancePreviewsOnTaskFinished
{
    public function handle(DaemonTaskFinished $event): void
    {
        $task = DaemonTask::query()->whereKey($event->taskId)->first(['id', 'team_id', 'type']);

        if (! $task instanceof DaemonTask) {
            return;
        }

        if (Preview::query()->where('team_id', $task->team_id)->where('status', PreviewStatus::Preparing->value)->exists()) {
            dispatch(new AdvancePreviewsJob($task->team_id))->delay(now()->addSeconds(5));
        }
    }
}
