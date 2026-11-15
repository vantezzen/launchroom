<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\DaemonFeature;
use App\Enums\ServerRole;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Support\ServerPlatform;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which tasks a server may lease (docs/03 §7): the ones pinned to it, or
 * unpinned tasks of its own team its roles can run; of those only the ones
 * whose stored requirements it meets (the launchroomd features the spec
 * needs, its platform, or for builds one its engine emulates, D53) and
 * whose deadline hasn't passed. LeaseTasks leases from it and
 * NudgeDaemonsForTask nudges by it, so a nudged server can lease the task.
 */
final class TaskEligibility
{
    /**
     * @param  Builder<DaemonTask>  $tasks
     * @return Builder<DaemonTask>
     */
    public static function leasableBy(Builder $tasks, Server $server): Builder
    {
        $roles = $server->roles->map(static fn (ServerRole $role): string => $role->value)->all();
        $platform = ServerPlatform::of($server);
        $platforms = ServerPlatform::buildable($server);

        $tasks
            ->where(static function (QueryBuilder $query) use ($server, $roles): void {
                $query
                    ->where(static fn (QueryBuilder $pinned) => $pinned->where('pinned_to_server', true)->where('server_id', $server->id))
                    ->orWhere(static fn (QueryBuilder $pool) => $pool
                        ->where('pinned_to_server', false)
                        ->where('team_id', $server->team_id)
                        ->where(static fn (QueryBuilder $role) => $role->whereNull('required_role')->orWhereIn('required_role', $roles)));
            })
            // A server that hasn't reported its architecture yet is not filtered.
            ->when($platform !== null, static fn (Builder $query) => $query->where(static function (QueryBuilder $arch) use ($platform, $platforms): void {
                $arch->whereNull('required_platform')
                    ->orWhere('required_platform', $platform)
                    ->orWhere(static fn (QueryBuilder $emulated) => $emulated->where('platform_emulation', true)->whereIn('required_platform', $platforms));
            }))
            ->where(static fn (QueryBuilder $query) => $query->whereNull('deadline_at')->orWhere('deadline_at', '>', now()));

        foreach (DaemonFeature::cases() as $feature) {
            if (! $server->supportsDaemonFeature($feature)) {
                $tasks->whereJsonDoesntContain('required_features', $feature->value);
            }
        }

        return $tasks;
    }

    public static function allows(DaemonTask $task, Server $server): bool
    {
        return self::leasableBy(DaemonTask::query()->whereKey($task->id), $server)->exists();
    }
}
