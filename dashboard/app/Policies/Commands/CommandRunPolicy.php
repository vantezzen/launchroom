<?php

declare(strict_types=1);

namespace App\Policies\Commands;

use App\Enums\Permission;
use App\Models\Commands\CommandRun;
use App\Models\ServiceInstance;
use App\Models\User;

/**
 * One-off commands (D105, docs/09 R33): the Console's permission
 * (`containers.exec`). An API token reaches it only with the opt-in `run`
 * ability (User::hasTeamPermission narrows by the token). Runs are output,
 * not sessions: everyone with the permission sees and can cancel every run
 * of the services they can reach.
 */
final class CommandRunPolicy
{
    public function viewAny(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::ExecContainers);
    }

    public function create(User $user, ServiceInstance $instance): bool
    {
        return $instance->removed_at === null
            && $user->hasTeamPermission($instance->team_id, Permission::ExecContainers);
    }

    public function view(User $user, CommandRun $run): bool
    {
        return $user->hasTeamPermission($run->team_id, Permission::ExecContainers);
    }

    public function cancel(User $user, CommandRun $run): bool
    {
        return $user->hasTeamPermission($run->team_id, Permission::ExecContainers);
    }
}
