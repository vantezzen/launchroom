<?php

declare(strict_types=1);

namespace App\Actions\Metrics;

use App\Events\Broadcasts\ServerMetricsUpdated;
use App\Models\Server;
use App\Queries\RouteOwners;
use App\Support\Metrics\Cast;
use App\Support\Metrics\MetricSamples;
use App\Support\Metrics\RequestStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stores the per-minute usage samples of a report (Report.metrics,
 * docs/design/direction.md §8.2) as 1-minute rows. Upserts, so a minute that
 * arrives twice (a report whose acknowledgement got lost) overwrites itself.
 *
 * Workload samples are attributed through the server's placements (the
 * workload id is the only thing the server knows); a workload whose
 * placement is already gone keeps the attribution of its earlier samples,
 * anything else unknown is dropped. Request metrics of edges are attributed
 * by route (docs/15 §1.3), and so are their request analytics (§5.3). Browsers of the team get a
 * `server.metrics` nudge to refetch.
 */
final readonly class RecordMetrics
{
    public function __construct(
        private RouteOwners $owners,
    ) {}

    /**
     * @return int host minutes written
     */
    public function handle(Server $server, mixed $metrics): int
    {
        $now = CarbonImmutable::now();
        $host = MetricSamples::host($metrics, $now);
        $workloads = MetricSamples::workloads($metrics, $now);
        $requests = MetricSamples::requests($metrics, $now);
        $analytics = MetricSamples::analytics($metrics, $now);

        if ($host !== []) {
            DB::table('server_metric_samples')->upsert(
                array_map(static fn (array $row): array => ['server_id' => $server->id, 'resolution' => 1, ...$row, 'at' => $row['at']->toIso8601String()], $host),
                ['server_id', 'resolution', 'at'],
                ['cpu_pct', 'build_cpu_cores', 'mem_used_mb', 'mem_total_mb', 'disk_used_mb', 'disk_total_mb', 'net_rx_bps', 'net_tx_bps', 'load1'],
            );
        }

        if ($workloads !== []) {
            $this->recordWorkloads($server, $workloads);
        }

        if ($requests !== []) {
            $this->recordRequests($server, $requests);
        }

        if ($analytics !== []) {
            $this->recordAnalytics($server, $analytics);
        }

        $minutes = array_map(static fn (array $row): int => $row['at']->getTimestamp(), [...$host, ...$workloads, ...$requests]);

        if ($minutes !== []) {
            $latest = max($minutes);
            broadcast(new ServerMetricsUpdated($server->team->public_id, $server->public_id, CarbonImmutable::createFromTimestampUTC($latest)->toIso8601String()));
        }

        return count($host);
    }

    /**
     * Request metrics of an edge (docs/15 §1.3): `rt_<ULID>` is a domain
     * route (`dom_<ULID>`) or an immutable deployment URL (`dpl_<ULID>`) of
     * the server's team. Routes seen before keep their attribution; unknown
     * ones (the dashboard's own route) are dropped.
     *
     * @param  list<array{route_id: string, at: CarbonImmutable, requests: int, status_2xx: int, status_3xx: int, status_4xx: int, status_5xx: int, bytes_in: int, bytes_out: int, latency: list<int>|null}>  $rows
     */
    private function recordRequests(Server $server, array $rows): void
    {
        $owners = $this->owners->handle($server, array_values(array_unique(array_column($rows, 'route_id'))));

        $insert = [];

        foreach ($rows as $row) {
            $owner = $owners[$row['route_id']] ?? null;

            if ($owner === null || $owner[0] === 0) {
                continue;
            }

            $insert[] = [
                'server_id' => $server->id,
                'route_id' => $row['route_id'],
                'resolution' => 1,
                'at' => $row['at']->toIso8601String(),
                'service_instance_id' => $owner[0],
                'project_id' => $owner[1],
                'requests' => $row['requests'],
                'status_2xx' => $row['status_2xx'],
                'status_3xx' => $row['status_3xx'],
                'status_4xx' => $row['status_4xx'],
                'status_5xx' => $row['status_5xx'],
                'bytes_in' => $row['bytes_in'],
                'bytes_out' => $row['bytes_out'],
                'latency' => RequestStats::literal($row['latency']),
            ];
        }

        foreach (array_chunk($insert, 1000) as $chunk) {
            DB::table('request_metric_samples')->upsert(
                $chunk,
                ['server_id', 'route_id', 'resolution', 'at'],
                ['requests', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx', 'bytes_in', 'bytes_out', 'latency'],
            );
        }
    }

    /**
     * Request analytics of an edge (docs/15 §5.3): 10-minute rows, attributed
     * like request metrics. Upserts, so a window that arrives twice
     * overwrites itself.
     *
     * @param  list<array{route_id: string, at: CarbonImmutable, requests: int, paths: list<array{p: string, r: int, e4: int, e5: int}>, other_requests: int, other_4xx: int, other_5xx: int, referrers: list<array{h: string, r: int}>, other_referrers: int, browsers: int, bots: int, other_agents: int}>  $rows
     */
    private function recordAnalytics(Server $server, array $rows): void
    {
        $owners = $this->owners->handle($server, array_values(array_unique(array_column($rows, 'route_id'))));
        $insert = [];

        foreach ($rows as $row) {
            $owner = $owners[$row['route_id']] ?? null;

            if ($owner === null || $owner[0] === 0) {
                continue;
            }

            $insert[] = [
                'server_id' => $server->id,
                'route_id' => $row['route_id'],
                'resolution' => RollupRequestAnalytics::TEN_MINUTES,
                'at' => $row['at']->toIso8601String(),
                'service_instance_id' => $owner[0],
                'project_id' => $owner[1],
                'requests' => $row['requests'],
                'paths' => json_encode($row['paths'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'other_requests' => $row['other_requests'],
                'other_4xx' => $row['other_4xx'],
                'other_5xx' => $row['other_5xx'],
                'referrers' => json_encode($row['referrers'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'other_referrers' => $row['other_referrers'],
                'browsers' => $row['browsers'],
                'bots' => $row['bots'],
                'other_agents' => $row['other_agents'],
            ];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('request_analytics_samples')->upsert(
                $chunk,
                ['server_id', 'route_id', 'resolution', 'at'],
                ['requests', 'paths', 'other_requests', 'other_4xx', 'other_5xx', 'referrers', 'other_referrers', 'browsers', 'bots', 'other_agents'],
            );
        }
    }

    /**
     * @param  list<array{workload_id: string, at: CarbonImmutable, cpu_cores: float|null, mem_mb: int|null}>  $rows
     */
    private function recordWorkloads(Server $server, array $rows): void
    {
        $ids = array_values(array_unique(array_column($rows, 'workload_id')));
        $owners = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            // Past samples first, live placements win.
            $known = DB::table('workload_metric_samples')
                ->where('server_id', $server->id)
                ->whereIn('workload_id', $chunk)
                ->distinct()
                ->get(['workload_id', 'service_instance_id', 'project_id', 'replica']);

            foreach ($known as $row) {
                $owners[Cast::string($row->workload_id ?? null)] = [Cast::id($row->service_instance_id ?? null), Cast::id($row->project_id ?? null), Cast::id($row->replica ?? null)];
            }

            $placed = DB::table('placements')
                ->join('service_instances', 'service_instances.id', '=', 'placements.service_instance_id')
                ->join('environments', 'environments.id', '=', 'service_instances.environment_id')
                ->where('placements.server_id', $server->id)
                ->where('placements.team_id', $server->team_id)
                ->whereIn('placements.workload_id', $chunk)
                ->get(['placements.workload_id', 'placements.service_instance_id', 'environments.project_id', 'placements.slot']);

            foreach ($placed as $row) {
                $owners[Cast::string($row->workload_id ?? null)] = [Cast::id($row->service_instance_id ?? null), Cast::id($row->project_id ?? null), Cast::id($row->slot ?? null)];
            }
        }

        $insert = [];

        foreach ($rows as $row) {
            $owner = $owners[$row['workload_id']] ?? null;

            if ($owner === null) {
                continue;
            }

            $insert[] = [
                'workload_id' => $row['workload_id'],
                'resolution' => 1,
                'at' => $row['at']->toIso8601String(),
                'server_id' => $server->id,
                'service_instance_id' => $owner[0],
                'project_id' => $owner[1],
                'replica' => $owner[2],
                'cpu_cores' => $row['cpu_cores'],
                'mem_mb' => $row['mem_mb'],
            ];
        }

        foreach (array_chunk($insert, 1000) as $chunk) {
            DB::table('workload_metric_samples')->upsert($chunk, ['workload_id', 'resolution', 'at'], ['cpu_cores', 'mem_mb']);
        }
    }
}
