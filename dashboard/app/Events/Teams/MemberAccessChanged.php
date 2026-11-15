<?php

declare(strict_types=1);

namespace App\Events\Teams;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user's access to a team shrank or ended: membership removed, role or
 * grants changed, account deleted. Whatever was decided under the old access
 * (open sessions, cached preview answers) listens and drops it (docs/03
 * §11.3). Dispatched after the surrounding transaction commits.
 */
final readonly class MemberAccessChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $teamId,
        public int $userId,
    ) {}
}
