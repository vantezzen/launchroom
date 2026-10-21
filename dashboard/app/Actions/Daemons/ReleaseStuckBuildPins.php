<?php

declare(strict_types=1);

namespace App\Actions\Daemons;

use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Events\DaemonTaskQueued;
use App\Models\DaemonTask;
use App\Models\Server;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds pinned to a server for cache locality (docs/04 §5) go back to the
 * pool of the platform's build servers when the pinned server can't take
 * them: it is not online, or nobody leased the task within
 * `launchroom.builds.pin_release_seconds` (busy, or died between polls). A
 * build re-queued after its lease expired on a server that went away keeps
 * its pin until then, too. Only `build`/`plan` tasks with the `build` role are
 * ever unpinned: releases, backups and updates must run where they are
 * pinned. Runs with the liveness check (every 15 s).
 */
final class ReleaseStuckBuildPins
{
    public function handle(): int
    {
        return DB::transaction(function (): int {
            $tasks = DaemonTask::query()
                ->where('status', DaemonTaskStatus::Pending->value)
                ->where('pinned_to_server', true)
                ->whereIn('type', [DaemonTaskType::Build->value, DaemonTaskType::Plan->value])
                ->where('required_role', ServerRole::Build->value)
                ->where(static fn (Builder $query) => $query
                    ->where('updated_at', '<', now()->subSeconds(config()->integer('launchroom.builds.pin_release_seconds')))
                    ->orWhereNotIn('server_id', Server::query()->select('id')->where('status', ServerStatus::Online->value)))
                ->lock('for update skip locked')
                ->limit(100)
                ->get();

            foreach ($tasks as $task) {
                $task->forceFill(['pinned_to_server' => false, 'server_id' => null])->save();
                // Nudges the platform's build servers (after commit).
                event(new DaemonTaskQueued($task));
            }

            return $tasks->count();
        });
    }
}
