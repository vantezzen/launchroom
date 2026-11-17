<?php

declare(strict_types=1);

namespace App\Policies\Registry;

use App\Enums\Permission;
use App\Models\Registry\RegistryCredential;
use App\Models\Team;
use App\Models\User;

/**
 * Private registry sign-in details (D91): members see which registries are
 * set up (never the password), owners and admins add, check and remove them
 * (Permission::ManageServers covers registry settings).
 */
final class RegistryCredentialPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::View);
    }

    public function view(User $user, RegistryCredential $credential): bool
    {
        return $user->hasTeamPermission($credential->team_id, Permission::View);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageServers);
    }

    public function update(User $user, RegistryCredential $credential): bool
    {
        return $user->hasTeamPermission($credential->team_id, Permission::ManageServers);
    }

    public function delete(User $user, RegistryCredential $credential): bool
    {
        return $this->update($user, $credential);
    }
}
