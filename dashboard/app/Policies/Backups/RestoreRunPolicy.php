<?php

declare(strict_types=1);

namespace App\Policies\Backups;

use App\Enums\Backups\RestoreMode;
use App\Enums\Permission;
use App\Models\Backups\RestoreRun;
use App\Models\ServiceInstance;
use App\Models\User;

/**
 * Restore as a new service adds a service (ManageProjects) and deploys it
 * (Deploy). Restoring into a service replaces its data: ManageBackups
 * (owners and admins) only (docs/14 §5.1). Swapping a restored copy in
 * stages connection, domain and removal changes (ManageProjects); deploying
 * them is a separate step.
 */
final class RestoreRunPolicy
{
    public function view(User $user, RestoreRun $run): bool
    {
        return $user->hasTeamPermission($run->team_id, Permission::View);
    }

    public function swap(User $user, RestoreRun $run): bool
    {
        return $user->hasTeamPermission($run->team_id, Permission::ManageProjects);
    }

    public function create(User $user, ServiceInstance $instance, RestoreMode $mode): bool
    {
        return match ($mode) {
            RestoreMode::NewService => $user->hasTeamPermission($instance->team_id, Permission::ManageProjects)
                && $user->hasTeamPermission($instance->team_id, Permission::Deploy),
            RestoreMode::InPlace => $user->hasTeamPermission($instance->team_id, Permission::ManageBackups),
        };
    }
}
