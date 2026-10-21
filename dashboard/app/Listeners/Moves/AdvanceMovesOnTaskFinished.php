<?php

declare(strict_types=1);

namespace App\Listeners\Moves;

use App\Enums\DaemonTaskType;
use App\Enums\Moves\ServerMoveStatus;
use App\Events\DaemonTaskFinished;
use App\Jobs\Moves\AdvanceServerMovesJob;
use App\Models\DaemonTask;
use App\Models\Moves\ServerMove;

/**
 * An export, import or backup task finished: a server move waiting for it
 * continues right away (docs/14 §13.2), a moment after the transfer and
 * backup listeners recorded the outcome.
 */
final class AdvanceMovesOnTaskFinished
{
    public function handle(DaemonTaskFinished $event): void
    {
        $task = DaemonTask::query()->whereKey($event->taskId)->first(['id', 'type']);

        if (! $task instanceof DaemonTask || ! in_array($task->type, [DaemonTaskType::DbExport, DaemonTaskType::VolumeExport, DaemonTaskType::DbImport, DaemonTaskType::VolumeImport, DaemonTaskType::Backup], true)) {
            return;
        }

        if (ServerMove::query()->whereIn('status', ServerMoveStatus::values([ServerMoveStatus::Running, ServerMoveStatus::Cancelling]))->exists()) {
            dispatch(new AdvanceServerMovesJob)->delay(now()->addSeconds(2));
        }
    }
}
