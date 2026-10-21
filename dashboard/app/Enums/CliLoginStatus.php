<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * A CLI sign-in request (docs/16 §4):
 *
 *     pending → approved → consumed
 *             → denied
 *
 * Pending until a member approves or denies it in the dashboard; an
 * approved request is consumed when the CLI picks its token up (once).
 */
enum CliLoginStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Consumed = 'consumed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Denied],
            self::Approved => [self::Consumed],
            self::Denied, self::Consumed => [],
        };
    }
}
