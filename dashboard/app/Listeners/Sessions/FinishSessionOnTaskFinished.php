<?php

declare(strict_types=1);

namespace App\Listeners\Sessions;

use App\Actions\Sessions\FinishSession;
use App\Enums\DaemonTaskType;
use App\Events\DaemonTaskFinished;
use App\Models\DaemonTask;

/**
 * A session task finished: close the session and write its "ended" audit
 * entry now (docs/03 §11.1).
 */
final readonly class FinishSessionOnTaskFinished
{
    public function __construct(
        private FinishSession $finish,
    ) {}

    public function handle(DaemonTaskFinished $event): void
    {
        $task = DaemonTask::query()->whereKey($event->taskId)->where('type', DaemonTaskType::Session->value)->first();

        if ($task instanceof DaemonTask) {
            $this->finish->handle($task);
        }
    }
}
