<?php

declare(strict_types=1);

namespace App\Policies\AppUpdates;

use App\Enums\Permission;
use App\Models\AppUpdates\AppUpdate;
use App\Models\ServiceInstance;
use App\Models\User;

/**
 * App updates (D106, docs/04 §14). Updating changes the service's image
 * (projects.manage, like Change image) and deploys (deployments.create), so
 * it needs both; so do the automatic-update setting and Check now, which
 * reaches out to a registry. Everyone in the team sees updates.
 */
final class AppUpdatePolicy
{
    public function viewAny(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::View);
    }

    public function create(User $user, ServiceInstance $instance): bool
    {
        return $instance->removed_at === null
            && $user->hasTeamPermission($instance->team_id, Permission::ManageProjects)
            && $user->hasTeamPermission($instance->team_id, Permission::Deploy);
    }

    public function view(User $user, AppUpdate $update): bool
    {
        return $user->hasTeamPermission($update->team_id, Permission::View);
    }

    public function cancel(User $user, AppUpdate $update): bool
    {
        return $user->hasTeamPermission($update->team_id, Permission::ManageProjects)
            && $user->hasTeamPermission($update->team_id, Permission::Deploy);
    }
}
