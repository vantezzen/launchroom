<?php

declare(strict_types=1);

namespace App\Actions\Sessions;

use App\Models\DaemonTask;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;
use App\Services\Sessions\SessionRelay;
use App\Services\Tasks\TaskCancellation;
use Illuminate\Support\Facades\DB;

/**
 * Ends a session (docs/03 §11.3, kill switch 1): cancels its task (a pending
 * one at once, a leased one on its next heartbeat) and sends a signed
 * `session.end` so the server stops within a second. The session row and
 * the "ended" audit entry are finished by FinishSession when the task ends.
 */
final readonly class EndSession
{
    public function __construct(
        private TaskCancellation $cancellation,
        private SessionRelay $relay,
    ) {}

    public function handle(InteractiveSession $session, ?User $by, string $reason = 'ended'): void
    {
        $sendEnd = DB::transaction(function () use ($session, $by): bool {
            $session = InteractiveSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $session->status->isLive()) {
                return false;
            }

            if ($by instanceof User && $session->ended_by_id === null) {
                $session->forceFill(['ended_by_id' => $by->id])->save();
            }

            $task = $session->daemonTask()->first();

            if ($task instanceof DaemonTask) {
                $this->cancellation->cancel($task);
            }

            return true;
        });

        if ($sendEnd) {
            $this->relay->end($session, $reason);
        }
    }
}
