<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * The web firewall of a service instance (docs/22 §6.2). Off sends nothing
 * (RouteSpec.waf omitted); log and block are the protocol's modes.
 */
enum WafMode: string
{
    case Off = 'off';
    case Log = 'log';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Log => 'Log only',
            self::Block => 'Block',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Off => 'Requests reach the service unchecked',
            self::Log => 'Requests that match attack rules are counted, not refused',
            self::Block => 'Requests that match attack rules are refused',
        };
    }
}
