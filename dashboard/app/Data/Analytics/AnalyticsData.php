<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use App\Data\Metrics\DeployMarkerData;
use Spatie\LaravelData\Data;

/**
 * Request analytics of an environment or one service (docs/15 §5.4).
 * Status series are request counts per bucket, aligned with `timestamps`
 * (bucket starts, UTC). `paths`, `referrers` and `agents` come from the
 * edges' analytics windows; `pathRequests` is the traffic they cover, so
 * the "other" row of the top routes is `pathRequests` minus the listed
 * paths. `serversWithoutPaths` names edges that served traffic in the range
 * but don't send paths yet (older servers).
 *
 * `blocked` counts requests the web firewall refused per bucket; they are
 * carved out of `status4xx` (a blocked request is a 403 in the access log),
 * so the classes still add up to the requests. `deploys` are the deploy
 * markers of the range (App\Queries\DeployMarkers).
 */
final class AnalyticsData extends Data
{
    /**
     * @param  list<string>  $timestamps
     * @param  list<int>  $status2xx
     * @param  list<int>  $status3xx
     * @param  list<int>  $status4xx
     * @param  list<int>  $status5xx
     * @param  list<int>  $statusOther
     * @param  list<int>  $blocked
     * @param  list<AnalyticsSplitData>  $servers
     * @param  list<AnalyticsSplitData>  $services
     * @param  list<AnalyticsPathData>  $paths
     * @param  list<AnalyticsReferrerData>  $referrers
     * @param  list<string>  $serversWithoutPaths
     * @param  list<DeployMarkerData>  $deploys
     */
    public function __construct(
        public string $range,
        public int $bucketSeconds,
        public array $timestamps,
        public array $status2xx,
        public array $status3xx,
        public array $status4xx,
        public array $status5xx,
        public array $statusOther,
        public AnalyticsTotalsData $totals,
        public array $servers,
        public array $services,
        public array $paths,
        public int $pathRequests,
        public array $referrers,
        public int $referrerRequests,
        public ?AnalyticsAgentsData $agents,
        public array $serversWithoutPaths,
        public array $blocked = [],
        public array $deploys = [],
    ) {}
}
