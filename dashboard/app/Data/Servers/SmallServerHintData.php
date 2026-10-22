<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * "This server is small" (docs/08 §14): the team's only build server has
 * little CPU or memory, so a build competes with the running apps.
 */
final class SmallServerHintData extends Data
{
    public function __construct(
        public string $serverId,
        public string $serverName,
        public int $cpus,
        /** 0 when unknown. */
        public int $memoryMb,
    ) {}
}
