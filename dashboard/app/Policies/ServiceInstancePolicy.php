<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServiceInstance;
use App\Models\User;

final class ServiceInstancePolicy
{
    public function view(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::View);
    }

    /**
     * Settings, domains, enable/disable (staged).
     */
    public function update(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::ManageProjects);
    }

    public function delete(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::ManageProjects);
    }
}
