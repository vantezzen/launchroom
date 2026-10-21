<?php

declare(strict_types=1);

namespace App\Policies\Git;

use App\Enums\Permission;
use App\Models\Git\GitHubInstallation;
use App\Models\Team;
use App\Models\User;

final class GitHubInstallationPolicy
{
    public function view(User $user, GitHubInstallation $installation): bool
    {
        return $user->hasTeamPermission($installation->team_id, Permission::View);
    }

    /**
     * Linking an installation after the GitHub setup redirect.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageServers);
    }

    /**
     * Re-reading the repository list from GitHub.
     */
    public function refresh(User $user, GitHubInstallation $installation): bool
    {
        return $installation->isActive()
            && $user->hasTeamPermission($installation->team_id, Permission::ManageServers);
    }
}
