<?php

declare(strict_types=1);

namespace App\Policies\Git;

use App\Enums\Permission;
use App\Models\Git\GitConnection;
use App\Models\Team;
use App\Models\User;

/**
 * Git connections (docs/19 §4) follow the GitHub App: owners and admins
 * connect and disconnect (`Permission::ManageServers`), everyone in the team
 * sees them and their health.
 */
final class GitConnectionPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::View);
    }

    public function view(User $user, GitConnection $connection): bool
    {
        return $user->hasTeamPermission($connection->team_id, Permission::View);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageServers);
    }

    public function update(User $user, GitConnection $connection): bool
    {
        return $user->hasTeamPermission($connection->team_id, Permission::ManageServers);
    }

    public function delete(User $user, GitConnection $connection): bool
    {
        return $user->hasTeamPermission($connection->team_id, Permission::ManageServers);
    }
}
