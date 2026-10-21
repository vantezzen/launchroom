<?php

declare(strict_types=1);

namespace App\Policies\Git;

use App\Enums\Permission;
use App\Models\Git\GitHubApp;
use App\Models\Team;
use App\Models\User;

/**
 * GitHub App settings are server-level settings: owner/admin only
 * (`Permission::ManageServers`, docs/09 R9). Everyone in
 * the team can see the connection and webhook health.
 */
final class GitHubAppPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::View);
    }

    public function view(User $user, GitHubApp $app): bool
    {
        return $user->hasTeamPermission($app->team_id, Permission::View);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageServers);
    }

    public function delete(User $user, GitHubApp $app): bool
    {
        return $user->hasTeamPermission($app->team_id, Permission::ManageServers);
    }
}
