<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\Contracts\TaskBackedAccessKind;
use App\Enums\Permission;
use App\Models\ApiToken;
use App\Models\Project;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\ServiceInstance;
use App\Models\Sessions\InteractiveSession;
use App\Models\Team;
use App\Models\User;

/**
 * The rules interactive sessions and remote access share (docs/09 R33,
 * R39). Opening needs the kind's own permission (`containers.exec` /
 * `databases.query`, never implied by deploying) and never works through an
 * API token. What was opened is the opener's while they still hold that
 * permission; owners and admins (members.manage) see and end everyone's in
 * the team.
 */
trait AuthorizesTaskBackedAccess
{
    public function open(User $user, ServiceInstance $instance, TaskBackedAccessKind $kind): bool
    {
        return $instance->removed_at === null
            && ! $user->currentApiToken() instanceof ApiToken
            && $user->hasTeamPermission($instance->team_id, $kind->permission());
    }

    /**
     * Whether the user may see the list on the service's tab at all (for a
     * project or team: on any of its services).
     */
    public function viewAny(User $user, ServiceInstance|Project|Team $scope, TaskBackedAccessKind $kind): bool
    {
        return $user->hasTeamPermission($scope instanceof Team ? $scope : $scope->team_id, $kind->permission());
    }

    /**
     * Whether lists of the team show everyone's, not only the user's own.
     */
    public function oversee(User $user, int $teamId): bool
    {
        return $user->hasTeamPermission($teamId, Permission::ManageMembers);
    }

    public function view(User $user, InteractiveSession|RemoteAccess $opened): bool
    {
        return $this->owns($user, $opened) || $this->oversees($user, $opened);
    }

    public function end(User $user, InteractiveSession|RemoteAccess $opened): bool
    {
        return $this->owns($user, $opened) || $this->oversees($user, $opened);
    }

    /**
     * Use what was opened (session commands, access credentials): the
     * opener only, and not through an API token.
     */
    private function opener(User $user, InteractiveSession|RemoteAccess $opened): bool
    {
        return $this->owns($user, $opened) && ! $user->currentApiToken() instanceof ApiToken;
    }

    private function owns(User $user, InteractiveSession|RemoteAccess $opened): bool
    {
        return $opened->user_id === $user->id && $user->hasTeamPermission($opened->team_id, $opened->kind->permission());
    }

    private function oversees(User $user, InteractiveSession|RemoteAccess $opened): bool
    {
        return $this->oversee($user, $opened->team_id) && $user->hasTeamPermission($opened->team_id, $opened->kind->permission());
    }
}
