<?php

declare(strict_types=1);

namespace App\Policies\RemoteAccess;

use App\Enums\Permission;
use App\Enums\RemoteAccess\RemoteAccessKind;
use App\Models\ApiToken;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\ServiceInstance;
use App\Models\User;

/**
 * Temporary remote access (docs/09 R39). Opening needs the same permission
 * as the matching session tab (`containers.exec` for SSH,
 * `databases.query` for databases); never through an API token. The
 * credentials are only for the person who opened it; owners and admins
 * (members.manage) see and end everyone's accesses in the team.
 */
final class RemoteAccessPolicy
{
    public function open(User $user, ServiceInstance $instance, RemoteAccessKind $kind): bool
    {
        return $instance->removed_at === null
            && ! $user->currentApiToken() instanceof ApiToken
            && $user->hasTeamPermission($instance->team_id, $kind->permission());
    }

    public function viewAny(User $user, ServiceInstance $instance, RemoteAccessKind $kind): bool
    {
        return $user->hasTeamPermission($instance->team_id, $kind->permission());
    }

    public function view(User $user, RemoteAccess $access): bool
    {
        return $this->owns($user, $access) || $this->oversees($user, $access);
    }

    /**
     * See the password and connection details: the opener only.
     */
    public function useCredentials(User $user, RemoteAccess $access): bool
    {
        return $this->owns($user, $access) && ! $user->currentApiToken() instanceof ApiToken;
    }

    public function end(User $user, RemoteAccess $access): bool
    {
        return $this->owns($user, $access) || $this->oversees($user, $access);
    }

    private function owns(User $user, RemoteAccess $access): bool
    {
        return $access->user_id === $user->id && $user->hasTeamPermission($access->team_id, $access->kind->permission());
    }

    private function oversees(User $user, RemoteAccess $access): bool
    {
        return $user->hasTeamPermission($access->team_id, Permission::ManageMembers)
            && $user->hasTeamPermission($access->team_id, $access->kind->permission());
    }
}
