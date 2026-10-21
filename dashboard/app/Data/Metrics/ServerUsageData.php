<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * A server card's usage: CPU and memory sparklines aligned with
 * FleetMetricsData::$timestamps (null = no sample in that bucket), the
 * current values and the "used by" breakdown. Empty series: hide the chart.
 */
final class ServerUsageData extends Data
{
    /**
     * @param  list<float|null>  $cpuPct
     * @param  list<float|null>  $memPct
     * @param  list<ProjectUsageData>  $usedBy
     */
    public function __construct(
        public string $serverId,
        public array $cpuPct,
        public array $memPct,
        public ?HostUsageNowData $current,
        public array $usedBy,
    ) {}
}
