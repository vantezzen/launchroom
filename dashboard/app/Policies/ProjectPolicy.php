<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

final class ProjectPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::View);
    }

    public function view(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team_id, Permission::View);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageProjects);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team_id, Permission::ManageProjects);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team_id, Permission::ManageProjects);
    }
}
