<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;

final class ConnectionPolicy
{
    public function view(User $user, Connection $connection): bool
    {
        return $user->hasTeamPermission($connection->team_id, Permission::View);
    }

    /**
     * Connections inject variables, so they need both permissions.
     */
    public function create(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team_id, Permission::ManageProjects)
            && $user->hasTeamPermission($project->team_id, Permission::WriteVariables);
    }

    public function delete(User $user, Connection $connection): bool
    {
        return $user->hasTeamPermission($connection->team_id, Permission::ManageProjects)
            && $user->hasTeamPermission($connection->team_id, Permission::WriteVariables);
    }
}
