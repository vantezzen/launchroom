<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use App\Enums\MetricRange;
use Spatie\LaravelData\Data;

/**
 * GET /p/{project}/-/metrics?range=&environment=&service=: per-service CPU
 * and memory of one environment (canvas node footers; with `service`, the
 * service panel's Metrics tab including per-replica series) plus deploy
 * markers in the range.
 */
final class ProjectMetricsData extends Data
{
    /**
     * @param  list<string>  $timestamps
     * @param  list<ServiceUsageSeriesData>  $services
     * @param  list<DeployMarkerData>  $deploys
     */
    public function __construct(
        public string $projectId,
        public string $environmentId,
        public MetricRange $range,
        public int $bucketSeconds,
        public array $timestamps,
        public array $services,
        public array $deploys,
        public ?EnvironmentTotalsData $totals = null,
    ) {}
}
