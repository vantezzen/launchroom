<?php

declare(strict_types=1);

namespace App\Listeners\RemoteAccess;

use App\Actions\RemoteAccess\FinishRemoteAccess;
use App\Enums\DaemonTaskType;
use App\Events\DaemonTaskFinished;
use App\Models\DaemonTask;

/**
 * An access task finished: close the access and write its "ended" audit
 * entry now (docs/03 §12.1).
 */
final readonly class FinishRemoteAccessOnTaskFinished
{
    public function __construct(
        private FinishRemoteAccess $finish,
    ) {}

    public function handle(DaemonTaskFinished $event): void
    {
        $task = DaemonTask::query()->whereKey($event->taskId)->where('type', DaemonTaskType::Access->value)->first();

        if ($task instanceof DaemonTask) {
            $this->finish->handle($task);
        }
    }
}
