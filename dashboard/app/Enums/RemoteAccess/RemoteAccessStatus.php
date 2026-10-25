<?php

declare(strict_types=1);

namespace App\Enums\RemoteAccess;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a temporary access: pending until its server leases the
 * access task, open while it runs, ended when it finishes.
 */
enum RemoteAccessStatus: string implements HasTransitionTable
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
