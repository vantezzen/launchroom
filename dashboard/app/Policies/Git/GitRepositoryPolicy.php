<?php

declare(strict_types=1);

namespace App\Policies\Git;

use App\Enums\Permission;
use App\Models\Git\GitRepository;
use App\Models\User;

/**
 * Like GitHub repositories: the picker is for creating services
 * (`Permission::ManageProjects`).
 */
final class GitRepositoryPolicy
{
    public function view(User $user, GitRepository $repository): bool
    {
        return $user->hasTeamPermission($repository->team_id, Permission::ManageProjects);
    }
}
