<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * The newest minute of a server's usage (at most 5 minutes old; older means
 * the server stopped sending and `current` is null).
 */
final class HostUsageNowData extends Data
{
    public function __construct(
        public string $at,
        public ?float $cpuPct,
        public ?int $memUsedMb,
        public ?int $memTotalMb,
        public ?float $memPct,
        public ?int $diskUsedMb,
        public ?int $diskTotalMb,
        public ?float $diskPct,
        public ?int $netRxBps,
        public ?int $netTxBps,
        public ?float $load1,
        public ?float $buildCpuCores = null,
    ) {}
}
