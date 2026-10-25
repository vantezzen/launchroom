<?php

declare(strict_types=1);

namespace App\Services\RemoteAccess;

use App\Enums\RemoteAccess\RemoteAccessKind;
use App\Models\DaemonTask;
use App\Models\RemoteAccess\RemoteAccess;

/**
 * Re-authorizes open accesses on every heartbeat (docs/09 R39): the
 * instance switch, the opener's membership and permission, the replica
 * still placed on that server (and, for a volume, the volume still there)
 * and the hard cap. Any "no" ends it.
 */
final readonly class RemoteAccessGate
{
    public function __construct(
        private RemoteAccessTargets $targets,
    ) {}

    public function stillAllowed(DaemonTask $task): bool
    {
        $access = RemoteAccess::query()
            ->where('daemon_task_id', $task->id)
            ->where('team_id', $task->team_id)
            ->with(['user', 'serviceInstance', 'volume'])
            ->first();

        return $access instanceof RemoteAccess && $this->allows($access);
    }

    public function allows(RemoteAccess $access): bool
    {
        if (config('launchroom.remote_access.enabled') !== true || ! $access->status->isLive() || $access->server_id === null || $access->expires_at->isPast()) {
            return false;
        }

        $user = $access->user;
        $user->forgetTeamRoles();

        if (! $user->hasTeamPermission($access->team_id, $access->kind->permission())) {
            return false;
        }

        return $access->kind === RemoteAccessKind::Volume
            ? $this->targets->volumeStillPlaced($access->serviceInstance, $access->volume, $access->workload_id, $access->server_id)
            : $this->targets->stillPlaced($access->serviceInstance, $access->workload_id, $access->server_id);
    }
}
