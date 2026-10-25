<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

/**
 * Request analytics of every project of a team (docs/15 §5.4): totals
 * against the previous period, requests per project and bucket (aligned
 * with `timestamps`), the split by server and the top paths across all
 * projects (`pathRequests` is the traffic the path data covers).
 */
final class TeamAnalyticsData extends Data
{
    /**
     * @param  list<string>  $timestamps
     * @param  list<AnalyticsProjectData>  $projects
     * @param  list<AnalyticsSplitData>  $servers
     * @param  list<AnalyticsTeamPathData>  $paths
     */
    public function __construct(
        public string $range,
        public int $bucketSeconds,
        public array $timestamps,
        public AnalyticsTotalsData $totals,
        public array $projects,
        public array $servers,
        public array $paths,
        public int $pathRequests,
    ) {}
}
