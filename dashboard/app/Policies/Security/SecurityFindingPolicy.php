<?php

declare(strict_types=1);

namespace App\Policies\Security;

use App\Enums\Permission;
use App\Models\Security\SecurityFinding;
use App\Models\Team;
use App\Models\User;

/**
 * Security page and findings (docs/22 §12): every member sees them
 * (`resources.view`); dismissing and reopening need `projects.manage`;
 * "Check now" and client networks of firewall events are for owners and
 * admins (`members.manage`).
 */
final class SecurityFindingPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::View);
    }

    public function view(User $user, SecurityFinding $finding): bool
    {
        return $user->hasTeamPermission($finding->team_id, Permission::View);
    }

    public function dismiss(User $user, SecurityFinding $finding): bool
    {
        return $user->hasTeamPermission($finding->team_id, Permission::ManageProjects);
    }

    public function reopen(User $user, SecurityFinding $finding): bool
    {
        return $user->hasTeamPermission($finding->team_id, Permission::ManageProjects);
    }

    public function checkNow(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageMembers);
    }

    public function viewClientNetworks(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageMembers);
    }
}
