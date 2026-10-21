<?php

declare(strict_types=1);

namespace App\Enums\Servers;

/**
 * The machine's operating system (Report.host.runtime.hostOs and
 * EnrollRequest.host.os, D53). On a Mac, launchroomd runs in the Linux VM of
 * a macOS engine.
 */
enum HostOs: string
{
    case Linux = 'linux';
    case Darwin = 'darwin';

    public function label(): string
    {
        return match ($this) {
            self::Linux => 'Linux',
            self::Darwin => 'macOS',
        };
    }
}
