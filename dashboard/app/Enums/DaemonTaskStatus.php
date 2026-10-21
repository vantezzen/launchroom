<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a daemon task (docs/03 §7, docs/07).
 *
 * A leased task whose lease expires goes back to pending (next attempt) or
 * fails when it is out of attempts.
 */
enum DaemonTaskStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    case Leased = 'leased';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Leased, self::Cancelled],
            self::Leased => [self::Pending, self::Succeeded, self::Failed, self::Cancelled],
            self::Succeeded, self::Failed, self::Cancelled => [],
        };
    }
}
