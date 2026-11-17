<?php

declare(strict_types=1);

namespace App\Data\Instance\System;

use Spatie\LaravelData\Data;

/**
 * One control-plane service as the main server's watchdog last saw it
 * (Report.controlPlane.services).
 *
 * state: running, unhealthy, restarting, stopped or missing.
 */
final class SystemServiceData extends Data
{
    public function __construct(
        public string $name,
        public string $label,
        public string $state,
        public ?string $startedAt,
        public int $restarts,
        public bool $oomKilled,
    ) {}
}
