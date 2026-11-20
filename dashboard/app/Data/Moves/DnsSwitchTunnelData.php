<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * Reaching the new dashboard before its domain points there: an SSH tunnel
 * to its local port (the dashboard listens on 127.0.0.1:8000 of its server;
 * loopback hosts are never redirected, docs/05 §4.1).
 */
final class DnsSwitchTunnelData extends Data
{
    public function __construct(
        public string $command,
        public string $url,
    ) {}
}
