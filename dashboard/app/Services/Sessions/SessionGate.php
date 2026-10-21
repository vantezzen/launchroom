<?php

declare(strict_types=1);

namespace App\Services\Sessions;

use App\Models\DaemonTask;
use App\Models\Sessions\InteractiveSession;

/**
 * Re-authorizes open sessions on every heartbeat (docs/03 §11.3): the
 * instance switch, the user's membership and permission, and that the
 * replica is still placed where the session runs. Any "no" ends it.
 */
final readonly class SessionGate
{
    public function __construct(
        private SessionTargets $targets,
    ) {}

    public function stillAllowed(DaemonTask $task): bool
    {
        $session = InteractiveSession::query()
            ->where('daemon_task_id', $task->id)
            ->where('team_id', $task->team_id)
            ->with(['user', 'serviceInstance'])
            ->first();

        return $session instanceof InteractiveSession && $this->allows($session);
    }

    public function allows(InteractiveSession $session): bool
    {
        $user = $session->user;

        if (config('launchroom.sessions.enabled') !== true || ! $session->status->isLive() || $session->server_id === null) {
            return false;
        }

        $user->forgetTeamRoles();

        return $user->hasTeamPermission($session->team_id, $session->kind->permission())
            && $this->targets->stillPlaced($session->serviceInstance, $session->workload_id, $session->server_id);
    }
}
