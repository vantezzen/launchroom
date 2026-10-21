<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use App\Enums\MetricRange;
use Spatie\LaravelData\Data;

/**
 * GET /servers/{server}/-/metrics?range=: the server page's stat tiles
 * (host series + current values), the "used by" meter and "what runs here"
 * (one row per service on this server, largest memory first).
 */
final class ServerMetricsData extends Data
{
    /**
     * @param  list<string>  $timestamps
     * @param  list<ProjectUsageData>  $usedBy
     * @param  list<ServiceUsageSeriesData>  $services
     * @param  list<DeployMarkerData>  $deploys
     */
    public function __construct(
        public string $serverId,
        public MetricRange $range,
        public int $bucketSeconds,
        public array $timestamps,
        public HostSeriesData $host,
        public ?HostUsageNowData $current,
        public array $usedBy,
        public array $services,
        /** Deploys of the services that ran here in the range (chart markers, docs/15 §2). */
        public array $deploys = [],
        /** Public traffic through this server as an edge; null without requests. */
        public ?RequestSeriesData $traffic = null,
    ) {}
}
