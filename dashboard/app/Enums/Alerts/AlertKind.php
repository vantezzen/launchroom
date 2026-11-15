<?php

declare(strict_types=1);

namespace App\Enums\Alerts;

/**
 * What exactly an alert of a rule with more than one cause is about, stored
 * with the alert so the chip's word never comes from parsing its title.
 */
enum AlertKind: string
{
    case Unhealthy = 'unhealthy';
    case Restarting = 'restarting';

    public function label(): string
    {
        return match ($this) {
            self::Unhealthy => 'Unhealthy',
            self::Restarting => 'Restarting',
        };
    }
}
