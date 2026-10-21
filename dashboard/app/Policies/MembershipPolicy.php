<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\User;

final class MembershipPolicy
{
    /**
     * Change someone else's role: needs members.manage and a role at least as
     * high as theirs (the new role is checked by the action).
     */
    public function update(User $user, Membership $membership): bool
    {
        $role = $user->roleIn($membership->team_id);

        return $role instanceof TeamRole
            && $membership->user_id !== $user->id
            && $role->can(Permission::ManageMembers)
            && $role->canGrant($membership->role);
    }

    /**
     * Grant or revoke a developer's Console/Data access (docs/09 R33): like
     * update, and only for roles that accept grants. Which permissions the
     * actor may hand out is checked by UpdateMemberPermissions.
     */
    public function grant(User $user, Membership $membership): bool
    {
        return $this->update($user, $membership)
            && array_any(Permission::grantable(), static fn (Permission $permission): bool => $membership->role->acceptsGrant($permission));
    }

    /**
     * Remove someone (same rule as update), or leave the team yourself.
     */
    public function delete(User $user, Membership $membership): bool
    {
        if ($membership->user_id === $user->id) {
            return $user->belongsToTeam($membership->team_id);
        }

        return $this->update($user, $membership);
    }
}
