<?php

declare(strict_types=1);

namespace App\Policies\Sessions;

use App\Enums\Permission;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Models\ApiToken;
use App\Models\ServiceInstance;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;

/**
 * Console and Data sessions (docs/09 R33). Opening needs the kind's own
 * permission (`containers.exec` / `databases.query`, never implied by
 * deploying); using a session is only for the person who opened it while
 * they still hold that permission; owners and admins (members.manage) see
 * and end everyone's sessions in the team.
 */
final class InteractiveSessionPolicy
{
    public function open(User $user, ServiceInstance $instance, InteractiveSessionKind $kind): bool
    {
        return $instance->removed_at === null
            && ! $user->currentApiToken() instanceof ApiToken
            && $user->hasTeamPermission($instance->team_id, $kind->permission());
    }

    /**
     * Whether the user may see the tab's sessions list at all.
     */
    public function viewAny(User $user, ServiceInstance $instance, InteractiveSessionKind $kind): bool
    {
        return $user->hasTeamPermission($instance->team_id, $kind->permission());
    }

    public function view(User $user, InteractiveSession $session): bool
    {
        return $this->owns($user, $session) || $this->oversees($user, $session);
    }

    /**
     * Join the channel, send commands: the opener only.
     */
    public function use(User $user, InteractiveSession $session): bool
    {
        return $this->owns($user, $session) && ! $user->currentApiToken() instanceof ApiToken;
    }

    public function end(User $user, InteractiveSession $session): bool
    {
        return $this->owns($user, $session) || $this->oversees($user, $session);
    }

    /**
     * Read a console transcript: the exec permission plus being its opener or
     * an owner/admin (the controller authorizes access).
     */
    public function viewRecording(User $user, InteractiveSession $session): bool
    {
        return $session->kind === InteractiveSessionKind::Console
            && $session->record
            && $user->hasTeamPermission($session->team_id, Permission::ExecContainers)
            && ($this->owns($user, $session) || $user->hasTeamPermission($session->team_id, Permission::ManageMembers));
    }

    private function owns(User $user, InteractiveSession $session): bool
    {
        return $session->user_id === $user->id && $user->hasTeamPermission($session->team_id, $session->kind->permission());
    }

    private function oversees(User $user, InteractiveSession $session): bool
    {
        return $user->hasTeamPermission($session->team_id, Permission::ManageMembers)
            && $user->hasTeamPermission($session->team_id, $session->kind->permission());
    }
}
