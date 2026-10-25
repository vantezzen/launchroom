<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * Shared prop for the sidebar's heavy-load notice (docs/08 §4): the
 * server running launchroom has been busy for several minutes. Only for
 * people who can manage that server.
 */
final class ControlPlaneLoadData extends Data
{
    public function __construct(
        public string $serverId,
        public string $serverName,
        /** Average CPU over the window, 0–100, null without CPU samples. */
        public ?float $cpuPct,
        /** Average memory in use over the window, 0–100. */
        public ?float $memoryPct,
    ) {}
}
