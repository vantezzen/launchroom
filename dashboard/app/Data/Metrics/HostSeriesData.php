<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * Host usage series of one server, aligned with the response's `timestamps`
 * (null = no sample in that bucket). Percentages are 0–100, rates bytes/s.
 */
final class HostSeriesData extends Data
{
    /**
     * @param  list<float|null>  $cpuPct
     * @param  list<float|null>  $memUsedMb
     * @param  list<float|null>  $memPct
     * @param  list<float|null>  $diskUsedMb
     * @param  list<float|null>  $diskPct
     * @param  list<float|null>  $netRxBps
     * @param  list<float|null>  $netTxBps
     * @param  list<float|null>  $load1
     */
    public function __construct(
        public array $cpuPct,
        public array $memUsedMb,
        public array $memPct,
        public array $diskUsedMb,
        public array $diskPct,
        public array $netRxBps,
        public array $netTxBps,
        public array $load1,
    ) {}
}
