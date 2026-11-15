<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A server leased a task and its secrets were sealed for it. Dispatched
 * inside the lease transaction, so an owner's change (a session opens and
 * its stored key is wiped) commits or rolls back with the lease.
 */
final readonly class DaemonTaskLeased
{
    use Dispatchable;

    public function __construct(
        public int $taskId,
        public int $serverId,
    ) {}
}
