<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\DaemonTaskPurpose;
use App\Enums\DaemonTaskStatus;
use App\Models\DaemonTask;

/**
 * Cancels daemon tasks: pending ones directly, leased ones by flagging them so
 * the next heartbeat tells the daemon to cancel (by context, never pkill); the
 * daemon then completes them as cancelled.
 */
final class TaskCancellation
{
    public function cancel(DaemonTask $task): void
    {
        $task = DaemonTask::query()->whereKey($task->id)->lockForUpdate()->first();

        if (! $task instanceof DaemonTask || $task->status->isTerminal()) {
            return;
        }

        if ($task->status === DaemonTaskStatus::Pending) {
            $task->transitionTo(DaemonTaskStatus::Cancelled);

            return;
        }

        if ($task->cancel_requested_at === null) {
            $task->forceFill(['cancel_requested_at' => now()])->save();
        }
    }

    public function cancelForDeployment(int $deploymentId, ?DaemonTaskPurpose $purpose = null): void
    {
        $tasks = DaemonTask::query()
            ->where('deployment_id', $deploymentId)
            ->open()
            ->when($purpose instanceof DaemonTaskPurpose, static fn ($query) => $query->where('purpose', $purpose?->value))
            ->get();

        foreach ($tasks as $task) {
            $this->cancel($task);
        }
    }
}
