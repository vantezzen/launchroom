<?php

declare(strict_types=1);

namespace App\Enums\Traces;

/**
 * What the web firewall decided about a traced request (docs/23 §2,
 * field `firewall`).
 */
enum TraceFirewallOutcome: string
{
    case Clean = 'clean';
    case Detected = 'detected';
    case Blocked = 'blocked';

    public static function fromByte(int $value): ?self
    {
        return match ($value) {
            0 => self::Clean,
            1 => self::Detected,
            2 => self::Blocked,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Clean => 'Passed',
            self::Detected => 'Would have been blocked',
            self::Blocked => 'Blocked',
        };
    }
}
