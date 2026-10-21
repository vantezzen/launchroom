<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use App\Enums\MetricRange;
use Spatie\LaravelData\Data;

/**
 * GET /servers/-/metrics?range=: every server of the current team. All
 * series share `timestamps` (bucket starts, ISO 8601, oldest first, ≤ 120).
 */
final class FleetMetricsData extends Data
{
    /**
     * @param  list<string>  $timestamps
     * @param  list<ServerUsageData>  $servers
     */
    public function __construct(
        public MetricRange $range,
        public int $bucketSeconds,
        public array $timestamps,
        public array $servers,
        /** The whole system's load (all given servers together). */
        public FleetTotalsData $totals,
    ) {}
}
