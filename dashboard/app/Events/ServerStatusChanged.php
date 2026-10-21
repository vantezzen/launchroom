<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\ServerStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A server's status changed. Dispatched after the surrounding transaction
 * commits; listeners only run side effects (docs/10 §2.1).
 */
final readonly class ServerStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $serverId,
        public ServerStatus $from,
        public ServerStatus $to,
    ) {}
}
