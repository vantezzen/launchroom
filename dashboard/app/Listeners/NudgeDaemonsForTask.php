<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\DaemonNudge;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Events\DaemonTaskQueued;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Services\Daemon\DaemonNudger;
use App\Services\Tasks\TaskEligibility;

/**
 * Nudges the reachable servers that may lease a newly queued task (docs/03
 * §4): the ones TaskEligibility lets lease it. Sent after commit.
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

        $servers = Server::query()
            ->reachable()
            ->when($task->pinned_to_server, static fn ($query) => $query->whereKey($task->server_id), static fn ($query) => $query->where('team_id', $task->team_id))
            ->get()
            ->filter(static fn (Server $server): bool => TaskEligibility::allows($task, $server))
            ->map(static fn (Server $server): string => $server->public_id)
            ->values()
            ->all();

        $this->nudger->nudge($servers, $task->type === DaemonTaskType::Logs ? DaemonNudge::LogsRequested : DaemonNudge::TaskAvailable);
    }
}
