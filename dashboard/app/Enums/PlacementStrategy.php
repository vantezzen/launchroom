<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the scheduler distributes a service's replicas (docs/02 §2).
 */
enum PlacementStrategy: string
{
    /** Replicas on different servers and failure domains (`zone`, else `provider` label) first. */
    case Spread = 'spread';

    /** As few servers as possible: fill a server before opening the next (best fit). */
    case Pack = 'pack';

    public function label(): string
    {
        return match ($this) {
            self::Spread => 'Spread',
            self::Pack => 'Pack',
        };
    }
}
