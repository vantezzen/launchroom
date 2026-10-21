<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\DaemonNudge;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerStatus;
use App\Events\DaemonTaskQueued;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Services\Daemon\DaemonNudger;

/**
 * Nudges the servers that may lease a newly queued task (docs/03 §4), with
 * the same eligibility LeaseTasks applies: the pinned server, else the
 * team's approved servers with the required role. Sent after commit.
 */
final readonly class NudgeDaemonsForTask
{
    public function __construct(
        private DaemonNudger $nudger,
    ) {}

    public function handle(DaemonTaskQueued $event): void
    {
        $task = DaemonTask::query()->whereKey($event->taskId)->first();

        if ($task === null || $task->status !== DaemonTaskStatus::Pending) {
            return;
        }

        $query = Server::query()
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value])
            ->when(
                $task->pinned_to_server,
                static fn ($query) => $query->whereKey($task->server_id),
                static fn ($query) => $query->where('team_id', $task->team_id)
                    ->when($task->required_role !== null, static fn ($roles) => $roles->whereJsonContains('roles', $task->required_role?->value)),
            )
            ->pluck('public_id');

        $servers = [];

        foreach ($query as $id) {
            if (is_string($id)) {
                $servers[] = $id;
            }
        }

        $this->nudger->nudge($servers, $task->type === DaemonTaskType::Logs ? DaemonNudge::LogsRequested : DaemonNudge::TaskAvailable);
    }
}
