<?php

declare(strict_types=1);

namespace App\Enums\Alerts;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * An alert is firing until the problem is gone; a resolved alert reopens
 * when the same problem returns within the cooldown (docs/15 §3.3).
 */
enum AlertStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Firing = 'firing';
    case Resolved = 'resolved';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Firing => [self::Resolved],
            self::Resolved => [self::Firing],
        };
    }
}
