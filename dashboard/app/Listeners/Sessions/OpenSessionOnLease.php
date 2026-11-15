<?php

declare(strict_types=1);

namespace App\Listeners\Sessions;

use App\Enums\TaskBackedAccessStatus;
use App\Events\DaemonTaskLeased;
use App\Models\Sessions\InteractiveSession;

/**
 * A session's task was leased with its key sealed to the server (docs/03
 * §11.1): the session opens and its stored key is wiped, in the lease's
 * transaction.
 */
final class OpenSessionOnLease
{
    public function handle(DaemonTaskLeased $event): void
    {
        $session = InteractiveSession::query()
            ->where('daemon_task_id', $event->taskId)
            ->where('status', TaskBackedAccessStatus::Pending->value)
            ->lockForUpdate()
            ->first();

        if ($session instanceof InteractiveSession) {
            $session->forceFill(['session_key' => null]);
            $session->transitionTo(TaskBackedAccessStatus::Open);
        }
    }
}
