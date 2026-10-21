<?php

declare(strict_types=1);

namespace App\Enums\Sessions;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of an interactive session (docs/03 §11.1): pending until its
 * server leases the session task, open while the task runs, ended when it
 * finishes (or was cancelled before a server took it).
 */
enum InteractiveSessionStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    case Open = 'open';
    case Ended = 'ended';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Open, self::Ended],
            self::Open => [self::Ended],
            self::Ended => [],
        };
    }

    public function isLive(): bool
    {
        return $this !== self::Ended;
    }
}
