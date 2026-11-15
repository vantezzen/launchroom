<?php

declare(strict_types=1);

namespace App\Listeners\RemoteAccess;

use App\Enums\TaskBackedAccessStatus;
use App\Events\DaemonTaskLeased;
use App\Models\RemoteAccess\RemoteAccess;

/**
 * An access's task was leased with its password sealed to the server
 * (docs/03 §12.1): the access opens. An access with keys has no password,
 * so its first heartbeat opens it (RecordAccessHeartbeat).
 */
final class OpenRemoteAccessOnLease
{
    public function handle(DaemonTaskLeased $event): void
    {
        $access = RemoteAccess::query()
            ->where('daemon_task_id', $event->taskId)
            ->where('server_id', $event->serverId)
            ->where('status', TaskBackedAccessStatus::Pending->value)
            ->whereNotNull('password')
            ->lockForUpdate()
            ->first();

        $access?->transitionTo(TaskBackedAccessStatus::Open);
    }
}
