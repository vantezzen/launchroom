<?php

declare(strict_types=1);

namespace App\Actions\RemoteAccess;

use App\Enums\DaemonTaskStatus;
use App\Enums\RemoteAccess\RemoteAccessStatus;
use App\Models\DaemonTask;
use App\Models\RemoteAccess\RemoteAccess;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Safety net (every minute): accesses no server picked up in time, and
 * live ones past their hard cap, are ended; accesses whose task finished
 * without the listener closing them are closed.
 */
final readonly class SweepRemoteAccess
{
    public function __construct(
        private EndRemoteAccess $end,
        private FinishRemoteAccess $finish,
    ) {}

    public function handle(): void
    {
        RemoteAccess::query()
            ->where(static fn (Builder $query) => $query
                ->where(static fn (Builder $pending) => $pending
                    ->where('status', RemoteAccessStatus::Pending->value)
                    ->where('created_at', '<', now()->subSeconds(config()->integer('launchroom.remote_access.pickup_seconds'))))
                ->orWhere(static fn (Builder $expired) => $expired
                    ->whereIn('status', [RemoteAccessStatus::Pending->value, RemoteAccessStatus::Open->value])
                    ->where('expires_at', '<', now()->subMinutes(2))))
            ->limit(200)
            ->get()
            ->each(fn (RemoteAccess $access) => $this->end->handle($access, null));

        DaemonTask::query()
            ->whereIn('id', RemoteAccess::query()
                ->select('daemon_task_id')
                ->whereIn('status', [RemoteAccessStatus::Pending->value, RemoteAccessStatus::Open->value])
                ->whereNotNull('daemon_task_id'))
            ->whereIn('status', [DaemonTaskStatus::Succeeded->value, DaemonTaskStatus::Failed->value, DaemonTaskStatus::Cancelled->value])
            ->limit(200)
            ->get()
            ->each(fn (DaemonTask $task) => $this->finish->handle($task));
    }
}
