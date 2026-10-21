<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;

final class TeamInvitationPolicy
{
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageMembers);
    }

    public function delete(User $user, TeamInvitation $invitation): bool
    {
        $role = $user->roleIn($invitation->team_id);

        return $role instanceof TeamRole && $role->canGrant($invitation->role);
    }
}
