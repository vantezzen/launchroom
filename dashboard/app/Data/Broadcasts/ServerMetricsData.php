<?php

declare(strict_types=1);

namespace App\Data\Broadcasts;

use Spatie\LaravelData\Data;

/** `server.metrics`: a server delivered usage minutes up to `at` (ISO 8601). */
final class ServerMetricsData extends Data
{
    public function __construct(
        public string $serverId,
        public string $at,
    ) {}
}
