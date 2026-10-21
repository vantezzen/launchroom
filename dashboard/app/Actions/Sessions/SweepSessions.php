<?php

declare(strict_types=1);

namespace App\Actions\Sessions;

use App\Enums\DaemonTaskStatus;
use App\Enums\Sessions\InteractiveSessionStatus;
use App\Models\DaemonTask;
use App\Models\Sessions\InteractiveSession;

/**
 * Safety net for interactive sessions (every minute): sessions no server
 * picked up within two minutes are ended (their server is gone or has
 * sessions switched off locally), and sessions whose task finished without
 * the listener closing them are closed.
 */
final readonly class SweepSessions
{
    private const int PICKUP_SECONDS = 120;

    public function __construct(
        private EndSession $end,
        private FinishSession $finish,
    ) {}

    public function handle(): void
    {
        InteractiveSession::query()
            ->where('status', InteractiveSessionStatus::Pending->value)
            ->where('created_at', '<', now()->subSeconds(self::PICKUP_SECONDS))
            ->limit(200)
            ->get()
            ->each(fn (InteractiveSession $session) => $this->end->handle($session, null, 'The server didn’t pick up the session.'));

        DaemonTask::query()
            ->whereIn('id', InteractiveSession::query()
                ->select('daemon_task_id')
                ->whereIn('status', [InteractiveSessionStatus::Pending->value, InteractiveSessionStatus::Open->value])
                ->whereNotNull('daemon_task_id'))
            ->whereIn('status', [DaemonTaskStatus::Succeeded->value, DaemonTaskStatus::Failed->value, DaemonTaskStatus::Cancelled->value])
            ->limit(200)
            ->get()
            ->each(fn (DaemonTask $task) => $this->finish->handle($task));
    }
}
