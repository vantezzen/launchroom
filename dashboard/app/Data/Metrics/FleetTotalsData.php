<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * The whole system's load (Servers page overview): CPU, memory and disk in
 * use as a share of the capacity of the servers that reported in each
 * bucket, aligned with FleetMetricsData::$timestamps. CPU is weighted by
 * cores, so a busy 2-core board doesn't look like a busy 32-core server.
 * Percentages of reporting servers (not absolute sums), so a server that
 * missed a minute doesn't read as a dip. Empty series: hide the chart.
 */
final class FleetTotalsData extends Data
{
    /**
     * @param  list<float|null>  $cpuPct
     * @param  list<float|null>  $memPct
     * @param  list<float|null>  $diskPct
     */
    public function __construct(
        public array $cpuPct,
        public array $memPct,
        public array $diskPct,
        public ?FleetCapacityData $current,
    ) {}
}
