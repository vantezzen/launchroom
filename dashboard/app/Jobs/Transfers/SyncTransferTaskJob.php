<?php

declare(strict_types=1);

namespace App\Jobs\Transfers;

use App\Actions\Transfers\AdvanceImports;
use App\Actions\Transfers\SyncTransferTasks;
use App\Enums\DaemonTaskType;
use App\Models\DaemonTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Applies a finished import/export task to its run right away, and moves
 * imports on when a backup (a safety snapshot) finished. The transfers tick
 * is the safety net.
 */
#[Backoff([5, 30])]
#[Timeout(60)]
#[Tries(3)]
final class SyncTransferTaskJob implements ShouldQueue
{
    use Queueable;

    public const array TYPES = [DaemonTaskType::DbImport, DaemonTaskType::VolumeImport, DaemonTaskType::DbExport, DaemonTaskType::VolumeExport, DaemonTaskType::Backup];

    public function __construct(
        public readonly int $taskId,
    ) {
        $this->afterCommit();
    }

    public static function dispatchFor(DaemonTask $task): void
    {
        if (in_array($task->type, self::TYPES, true)) {
            dispatch(new self($task->id));
        }
    }

    public function handle(SyncTransferTasks $sync, AdvanceImports $advance): void
    {
        $task = DaemonTask::query()->whereKey($this->taskId)->first();

        if ($task instanceof DaemonTask && $task->type !== DaemonTaskType::Backup) {
            $sync->handle($task);
        }

        $advance->handle();
    }
}
