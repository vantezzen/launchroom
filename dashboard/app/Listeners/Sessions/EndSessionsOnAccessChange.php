<?php

declare(strict_types=1);

namespace App\Listeners\Sessions;

use App\Actions\Sessions\EndUserSessions;
use App\Events\Teams\MemberAccessChanged;

final readonly class EndSessionsOnAccessChange
{
    public function __construct(
        private EndUserSessions $endSessions,
    ) {}

    public function handle(MemberAccessChanged $event): void
    {
        $this->endSessions->handle($event->teamId, $event->userId);
    }
}
