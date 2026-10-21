<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\VariableType;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;

final class VariablePolicy
{
    public function view(User $user, Variable $variable): bool
    {
        return $user->hasTeamPermission($variable->team_id, Permission::View);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team_id, Permission::WriteVariables);
    }

    public function update(User $user, Variable $variable): bool
    {
        return $variable->connection_id === null
            && $user->hasTeamPermission($variable->team_id, Permission::WriteVariables);
    }

    public function delete(User $user, Variable $variable): bool
    {
        return $variable->connection_id === null
            && $user->hasTeamPermission($variable->team_id, Permission::WriteVariables);
    }

    /**
     * Config values are readable by anyone who can see the service; secrets
     * need their own permission (docs/06 §3.1, docs/09 R9).
     */
    public function reveal(User $user, Variable $variable): bool
    {
        return $user->hasTeamPermission(
            $variable->team_id,
            $variable->type === VariableType::Secret ? Permission::RevealSecrets : Permission::View,
        );
    }
}
