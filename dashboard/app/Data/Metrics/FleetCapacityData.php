<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * Capacity and usage now, summed over the servers whose newest minute is
 * at most 5 minutes old (`servers` of them).
 */
final class FleetCapacityData extends Data
{
    public function __construct(
        public int $servers,
        public int $cpuCores,
        /** Cores in use (CPU percentage × cores, summed). */
        public float $cpuUsedCores,
        public ?float $cpuPct,
        public int $memUsedMb,
        public int $memTotalMb,
        public ?float $memPct,
        public int $diskUsedMb,
        public int $diskTotalMb,
        public ?float $diskPct,
    ) {}
}
