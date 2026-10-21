<?php

declare(strict_types=1);

namespace App\Policies\Dockerfiles;

use App\Enums\Permission;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\User;

/**
 * Generations belong to a service instance: viewing needs View in its team,
 * starting, using, cancelling and opening a pull request need
 * ManageProjects (the service's `update`). The opt-in is checked by the
 * actions (docs/20 §1).
 */
final class DockerfileGenerationPolicy
{
    public function view(User $user, DockerfileGeneration $generation): bool
    {
        return $user->hasTeamPermission($generation->team_id, Permission::View);
    }

    public function update(User $user, DockerfileGeneration $generation): bool
    {
        return $user->hasTeamPermission($generation->team_id, Permission::ManageProjects);
    }
}
