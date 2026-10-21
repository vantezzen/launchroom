<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\DaemonTask;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A task became leasable (created, or re-queued after an expired lease).
 * App\Listeners\NudgeDaemonsForTask nudges the servers that may lease it.
 */
final readonly class DaemonTaskQueued
{
    use Dispatchable;

    public int $taskId;

    public function __construct(DaemonTask $task)
    {
        $this->taskId = $task->id;
    }
}
