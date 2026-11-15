<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\VariableType;
use App\Models\Project;
use App\Models\Team;
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
        return $this->writeAny($user, $project->team_id);
    }

    /**
     * Whether the user may add or change variables in any of the team's
     * projects (the command palette's variable commands).
     */
    public function writeAny(User $user, Team|int $team): bool
    {
        return $user->hasTeamPermission($team, Permission::WriteVariables);
    }

    /**
     * With $type, the type it changes to ({@see retype()}).
     */
    public function update(User $user, Variable $variable, ?VariableType $type = null): bool
    {
        return ! $variable->isManagedByConnection()
            && $user->hasTeamPermission($variable->team_id, Permission::WriteVariables)
            && (! $type instanceof VariableType || $this->retype($user, $variable, $type));
    }

    /**
     * Turning a secret into config makes its value readable: only for members
     * who may reveal it anyway (docs/06 §3.1). Never without a user.
     */
    public function retype(?User $user, Variable $variable, VariableType $type): bool
    {
        if ($variable->type !== VariableType::Secret || $type !== VariableType::Config) {
            return true;
        }

        return $user instanceof User && $this->reveal($user, $variable);
    }

    public function delete(User $user, Variable $variable): bool
    {
        return ! $variable->isManagedByConnection()
            && $user->hasTeamPermission($variable->team_id, Permission::WriteVariables);
    }

    /**
     * Config values are readable by anyone who can see the service; secrets
     * need their own permission (docs/06 §3.1, docs/09 R9).
     */
    public function reveal(User $user, Variable $variable): bool
    {
        return $variable->type === VariableType::Secret
            ? $user->hasTeamPermission($variable->team_id, Permission::RevealSecrets)
            : $user->hasTeamPermission($variable->team_id, Permission::View);
    }

    /**
     * Secret values of a project at once (resolved env export, the editor's
     * reveal buttons).
     */
    public function revealSecrets(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team_id, Permission::RevealSecrets);
    }
}
