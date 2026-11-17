<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\DaemonNudge;
use App\Enums\DaemonTaskPurpose;
use App\Enums\DaemonTaskStatus;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Services\Daemon\DaemonNudger;

/**
 * Cancels daemon tasks: pending ones directly, leased ones by flagging them so
 * the next heartbeat tells the daemon to cancel (by context, never pkill); the
 * daemon then completes them as cancelled. The holding server is nudged
 * ("task.cancelled", after commit) to heartbeat at once, so a closed session
 * or access stops within a round trip, not at its next scheduled heartbeat.
 */
final readonly class TaskCancellation
{
    public function __construct(
        private DaemonNudger $nudger,
    ) {}

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

        $server = $task->server_id === null ? null : Server::query()->whereKey($task->server_id)->value('public_id');

        if (is_string($server)) {
            $this->nudger->nudge([$server], DaemonNudge::TaskCancelled);
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
