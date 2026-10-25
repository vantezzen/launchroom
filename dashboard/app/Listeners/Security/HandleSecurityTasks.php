<?php

declare(strict_types=1);

namespace App\Listeners\Security;

use App\Actions\Security\AdvanceInvestigation;
use App\Actions\Security\FailInventory;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Events\DaemonTaskFinished;
use App\Models\DaemonTask;

/**
 * Security tasks that finished (docs/22 §3.1, §7.2): a failed `inventory`
 * task fails its inventory; an `inspect_container` task moves its
 * investigation on (its result was stored by CompleteTask already).
 */
final readonly class HandleSecurityTasks
{
    public function __construct(
        private FailInventory $failInventory,
        private AdvanceInvestigation $advance,
    ) {}

    public function handle(DaemonTaskFinished $event): void
    {
        $task = DaemonTask::query()
            ->whereKey($event->taskId)
            ->whereIn('type', [DaemonTaskType::Inventory->value, DaemonTaskType::InspectContainer->value])
            ->first();

        if (! $task instanceof DaemonTask) {
            return;
        }

        if ($task->type === DaemonTaskType::Inventory) {
            if ($task->status !== DaemonTaskStatus::Succeeded) {
                $this->failInventory->handle($task);
            }

            return;
        }

        $this->advance->handle($task);
    }
}
