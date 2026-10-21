<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;

/**
 * Deny by default; every check derives the role from the team itself.
 */
final class TeamPolicy
{
    /**
     * New teams: instance owners, and anyone who owns or administers a team
     * already. Developers and viewers are guests in someone else's team; an
     * owner or admin can make them one (docs/09 R9).
     */
    public function create(User $user): bool
    {
        return $user->is_instance_owner === true
            || Membership::query()
                ->where('user_id', $user->id)
                ->whereIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
                ->exists();
    }

    public function view(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::View);
    }

    public function switchTo(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    public function update(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageTeam);
    }

    /**
     * Search the logs of every project at once (docs/08 §12): owners and
     * admins. Everyone with View still searches one project at a time.
     */
    public function searchLogs(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::View)
            && $user->hasTeamPermission($team, Permission::ManageServers);
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageMembers);
    }
}
