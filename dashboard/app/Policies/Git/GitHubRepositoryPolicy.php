<?php

declare(strict_types=1);

namespace App\Policies\Git;

use App\Enums\Permission;
use App\Models\Git\GitHubRepository;
use App\Models\Team;
use App\Models\User;

/**
 * The repo picker is for creating services, so it follows
 * `Permission::ManageProjects` (owner, admin, developer).
 */
final class GitHubRepositoryPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageProjects);
    }

    public function view(User $user, GitHubRepository $repository): bool
    {
        return $user->hasTeamPermission($repository->team_id, Permission::ManageProjects);
    }
}
