<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;

final class ServicePolicy
{
    public function view(User $user, Service $service): bool
    {
        return $user->hasTeamPermission($service->team_id, Permission::View);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team_id, Permission::ManageProjects);
    }

    public function update(User $user, Service $service): bool
    {
        return $user->hasTeamPermission($service->team_id, Permission::ManageProjects);
    }

    public function delete(User $user, Service $service): bool
    {
        return $user->hasTeamPermission($service->team_id, Permission::ManageProjects);
    }
}
