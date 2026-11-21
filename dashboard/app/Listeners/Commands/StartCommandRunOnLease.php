<?php

declare(strict_types=1);

namespace App\Listeners\Commands;

use App\Enums\Commands\CommandRunStatus;
use App\Events\DaemonTaskLeased;
use App\Models\Commands\CommandRun;

/**
 * A one-off command's task was leased (D105): the server pulls the image
 * and starts the container, so the run is running from here on.
 */
final class StartCommandRunOnLease
{
    public function handle(DaemonTaskLeased $event): void
    {
        $run = CommandRun::query()
            ->where('daemon_task_id', $event->taskId)
            ->where('server_id', $event->serverId)
            ->where('status', CommandRunStatus::Queued->value)
            ->lockForUpdate()
            ->first();

        $run?->transitionTo(CommandRunStatus::Running);
    }
}
