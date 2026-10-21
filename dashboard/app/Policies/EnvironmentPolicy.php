<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EnvironmentKind;
use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;

final class EnvironmentPolicy
{
    public function view(User $user, Environment $environment): bool
    {
        return $user->hasTeamPermission($environment->team_id, Permission::View);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team_id, Permission::ManageProjects);
    }

    public function update(User $user, Environment $environment): bool
    {
        return $user->hasTeamPermission($environment->team_id, Permission::ManageProjects);
    }

    /**
     * Deploy staged changes, discard them, roll back (docs/09 R9 "Deploy").
     */
    public function deploy(User $user, Environment $environment): bool
    {
        return $user->hasTeamPermission($environment->team_id, Permission::Deploy);
    }

    /**
     * Compare & promote into this environment (docs/06 §6): stages changes,
     * including variables, in it.
     */
    public function promote(User $user, Environment $environment): bool
    {
        return $user->hasTeamPermission($environment->team_id, Permission::ManageProjects)
            && $user->hasTeamPermission($environment->team_id, Permission::WriteVariables);
    }

    public function delete(User $user, Environment $environment): bool
    {
        return $environment->kind !== EnvironmentKind::Production
            && $user->hasTeamPermission($environment->team_id, Permission::ManageProjects);
    }
}
