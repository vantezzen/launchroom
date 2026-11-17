<?php

declare(strict_types=1);

namespace App\Policies\Sessions;

use App\Enums\Permission;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTaskBackedAccess;

/**
 * Console and Data sessions (docs/09 R33).
 */
final class InteractiveSessionPolicy
{
    use AuthorizesTaskBackedAccess;

    /**
     * Join the channel, send commands.
     */
    public function use(User $user, InteractiveSession $session): bool
    {
        return $this->opener($user, $session);
    }

    /**
     * Read a console transcript: the exec permission plus being its opener or
     * an owner/admin.
     */
    public function viewRecording(User $user, InteractiveSession $session): bool
    {
        return in_array($session->kind, [InteractiveSessionKind::Console, InteractiveSessionKind::Recovery], true)
            && $session->record
            && $user->hasTeamPermission($session->team_id, Permission::ExecContainers)
            && ($this->owns($user, $session) || $this->oversee($user, $session->team_id));
    }
}
