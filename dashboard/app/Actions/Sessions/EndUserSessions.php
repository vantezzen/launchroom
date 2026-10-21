<?php

declare(strict_types=1);

namespace App\Actions\Sessions;

use App\Enums\Sessions\InteractiveSessionStatus;
use App\Models\Sessions\InteractiveSession;

/**
 * Ends every live session of a user in a team (membership removed, role or
 * grants changed; docs/03 §11.3). Heartbeats would end them within seconds
 * anyway; this makes it immediate.
 */
final readonly class EndUserSessions
{
    public function __construct(
        private EndSession $endSession,
    ) {}

    public function handle(int $teamId, int $userId): void
    {
        InteractiveSession::query()
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->whereIn('status', [InteractiveSessionStatus::Pending->value, InteractiveSessionStatus::Open->value])
            ->get()
            ->each(fn (InteractiveSession $session) => $this->endSession->handle($session, null, 'Your access changed.'));
    }
}
