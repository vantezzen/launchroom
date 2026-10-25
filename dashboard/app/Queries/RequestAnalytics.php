<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Analytics\AnalyticsAgentsData;
use App\Data\Analytics\AnalyticsData;
use App\Data\Analytics\AnalyticsPathData;
use App\Data\Analytics\AnalyticsReferrerData;
use App\Data\Analytics\AnalyticsSplitData;
use App\Data\Analytics\AnalyticsTotalsData;
use App\Enums\AnalyticsRange;
use App\Enums\DaemonFeature;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Request analytics reads (docs/15 §5.4). Request counts come from the
 * request metrics (`request_metric_samples`, §1.3); paths, referrers and
 * client classes from `request_analytics_samples`. Each read takes every
 * stored resolution where it is complete: the finest rows for the last two
 * hours (coarser rows are recomputed every ten minutes), hourly-capable rows
 * before that and, for daily buckets, daily rows before yesterday.
 *
 * Callers authorize and pass service instance ids they resolved inside the
 * team; `names` maps instance ids to service names for the split.
 */
final readonly class RequestAnalytics
{
    public const int TOP_PATHS = 25;

    public const int TOP_REFERRERS = 10;

    /**
     * @param  list<int>  $instances
     * @param  array<int, array{id: string, name: string}>  $names  service public id and name by instance id
     */
    public function handle(array $instances, array $names, AnalyticsRange $range, ?CarbonImmutable $now = null): AnalyticsData
    {
        $now ??= CarbonImmutable::now();
        $bucket = $range->bucketSeconds();
        $count = $range->buckets();
        $start = intdiv($now->getTimestamp(), $bucket) * $bucket - ($count - 1) * $bucket;
        $from = CarbonImmutable::createFromTimestampUTC($start);
        $previousFrom = $from->subSeconds($count * $bucket);
        $timestamps = [];

        for ($i = 0; $i < $count; $i++) {
            $timestamps[] = CarbonImmutable::createFromTimestampUTC($start + $i * $bucket)->toIso8601String();
        }

        $empty = array_fill(0, $count, 0);
        $series = ['s2' => $empty, 's3' => $empty, 's4' => $empty, 's5' => $empty, 'other' => $empty];
        $byServer = [];
        $byInstance = [];
        $totals = ['requests' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 's5' => 0];

        if ($instances !== []) {
            $rows = $this->counts($instances, $range, $now, $from)
                ->selectRaw("server_id, service_instance_id, extract(epoch from date_bin(make_interval(secs => ?), at, TIMESTAMPTZ 'epoch'))::bigint as b, sum(requests) as requests, sum(status_2xx) as s2, sum(status_3xx) as s3, sum(status_4xx) as s4, sum(status_5xx) as s5", [$bucket])
                ->groupBy('server_id', 'service_instance_id', 'b')
                ->get();

            foreach ($rows as $row) {
                $b = Cast::int($row->b ?? null);
                $i = $b === null ? -1 : intdiv($b - $start, $bucket);

                if ($i < 0 || $i >= $count) {
                    continue;
                }

                $requests = Cast::int($row->requests ?? null) ?? 0;
                $classes = [];

                foreach (['s2', 's3', 's4', 's5'] as $key) {
                    $classes[$key] = Cast::int($row->{$key} ?? null) ?? 0;
                    $series[$key][$i] += $classes[$key];
                    $totals[$key] += $classes[$key];
                }

                $series['other'][$i] += max(0, $requests - array_sum($classes));
                $totals['requests'] += $requests;

                $this->bump($byServer, Cast::id($row->server_id ?? null), $i, $requests, $classes['s5'], $empty);
                $this->bump($byInstance, Cast::id($row->service_instance_id ?? null), $i, $requests, $classes['s5'], $empty);
            }
        }

        $previous = $instances === [] ? null : $this->counts($instances, $range, $now, $previousFrom, $from)
            ->selectRaw('sum(requests) as requests, sum(status_5xx) as s5')
            ->first();
        $previousRequests = Cast::int($previous->requests ?? null);
        $previousErrors = Cast::int($previous->s5 ?? null) ?? 0;

        $details = $this->details($instances, $range, $now, $from);

        return new AnalyticsData(
            range: $range->value,
            bucketSeconds: $bucket,
            timestamps: $timestamps,
            status2xx: array_values($series['s2']),
            status3xx: array_values($series['s3']),
            status4xx: array_values($series['s4']),
            status5xx: array_values($series['s5']),
            statusOther: array_values($series['other']),
            totals: new AnalyticsTotalsData(
                $totals['requests'], $totals['s2'], $totals['s3'], $totals['s4'], $totals['s5'],
                $this->rate($totals['s5'], $totals['requests']),
                $previousRequests !== null && $previousRequests > 0 ? $previousRequests : null,
                $previousRequests !== null && $previousRequests > 0 ? $this->rate($previousErrors, $previousRequests) : null,
            ),
            servers: $this->servers($byServer),
            services: $this->split($byInstance, $names),
            paths: $details['paths'],
            pathRequests: $details['pathRequests'],
            referrers: $details['referrers'],
            referrerRequests: $details['referrerRequests'],
            agents: $details['agents'],
            serversWithoutPaths: $this->serversWithoutPaths(array_keys($byServer)),
        );
    }

    /**
     * Requests per hour over the last 24 hours, per service instance (the
     * project card sparkline): `[instance id => 24 counts]`, oldest first.
     *
     * @param  list<int>  $instances
     * @return array<int, list<int>>
     */
    public function lastDay(array $instances, ?CarbonImmutable $now = null): array
    {
        if ($instances === []) {
            return [];
        }

        $now ??= CarbonImmutable::now();
        $start = intdiv($now->getTimestamp(), 3600) * 3600 - 23 * 3600;
        $rows = $this->counts($instances, AnalyticsRange::Day, $now, CarbonImmutable::createFromTimestampUTC($start))
            ->selectRaw("service_instance_id, extract(epoch from date_bin('1 hour', at, TIMESTAMPTZ 'epoch'))::bigint as b, sum(requests) as requests")
            ->groupBy('service_instance_id', 'b')
            ->get();
        $out = [];

        foreach ($rows as $row) {
            $i = intdiv((Cast::int($row->b ?? null) ?? 0) - $start, 3600);

            if ($i < 0 || $i >= 24) {
                continue;
            }

            $key = Cast::id($row->service_instance_id ?? null);
            $out[$key] ??= array_fill(0, 24, 0);
            $out[$key][$i] += Cast::int($row->requests ?? null) ?? 0;
        }

        return $out;
    }

    /**
     * Request metric rows of the selection in [from, to), each stored
     * resolution where it is complete.
     *
     * @param  list<int>  $instances
     */
    private function counts(array $instances, AnalyticsRange $range, CarbonImmutable $now, CarbonImmutable $from, ?CarbonImmutable $to = null): Builder
    {
        return AnalyticsTiers::apply(DB::table('request_metric_samples')->whereIn('service_instance_id', $instances), $range, $now, $from, $to, AnalyticsTiers::REQUESTS);
    }

    /**
     * Analytics rows of the selection since `from`.
     *
     * @param  list<int>  $instances
     */
    private function analytics(array $instances, AnalyticsRange $range, CarbonImmutable $now, CarbonImmutable $from): Builder
    {
        return AnalyticsTiers::apply(DB::table('request_analytics_samples')->whereIn('service_instance_id', $instances), $range, $now, $from, null, AnalyticsTiers::ANALYTICS);
    }

    /**
     * Top paths, referrers and client classes of the range.
     *
     * @param  list<int>  $instances
     * @return array{paths: list<AnalyticsPathData>, pathRequests: int, referrers: list<AnalyticsReferrerData>, referrerRequests: int, agents: AnalyticsAgentsData|null}
     */
    private function details(array $instances, AnalyticsRange $range, CarbonImmutable $now, CarbonImmutable $from): array
    {
        $none = ['paths' => [], 'pathRequests' => 0, 'referrers' => [], 'referrerRequests' => 0, 'agents' => null];

        if ($instances === []) {
            return $none;
        }

        $sums = $this->analytics($instances, $range, $now, $from)
            ->selectRaw('sum(requests) as requests, sum(browsers) as browsers, sum(bots) as bots, sum(other_agents) as other_agents, sum(other_referrers) as other_referrers')
            ->first();
        $covered = Cast::int($sums->requests ?? null) ?? 0;

        if ($covered <= 0) {
            return $none;
        }

        $paths = DB::query()
            ->fromSub($this->analytics($instances, $range, $now, $from)->select('paths'), 's')
            ->crossJoin(DB::raw('jsonb_array_elements(s.paths) as e'))
            ->selectRaw("e->>'p' as path, sum((e->>'r')::bigint) as r, sum((e->>'e4')::bigint) as e4, sum((e->>'e5')::bigint) as e5")
            ->groupByRaw("e->>'p'")
            ->orderByDesc('r')
            ->orderBy('path')
            ->limit(self::TOP_PATHS)
            ->get()
            ->map(static fn (object $row): AnalyticsPathData => new AnalyticsPathData(
                Cast::string($row->path ?? null),
                Cast::int($row->r ?? null) ?? 0,
                Cast::int($row->e4 ?? null) ?? 0,
                Cast::int($row->e5 ?? null) ?? 0,
            ))
            ->values()
            ->all();

        $referrerRows = DB::query()
            ->fromSub($this->analytics($instances, $range, $now, $from)->select('referrers'), 's')
            ->crossJoin(DB::raw('jsonb_array_elements(s.referrers) as e'))
            ->selectRaw("e->>'h' as host, sum((e->>'r')::bigint) as r")
            ->groupByRaw("e->>'h'")
            ->orderByDesc('r')
            ->orderBy('host')
            ->get();
        $referrers = $referrerRows->take(self::TOP_REFERRERS)
            ->map(static fn (object $row): AnalyticsReferrerData => new AnalyticsReferrerData(Cast::string($row->host ?? null), Cast::int($row->r ?? null) ?? 0))
            ->values()
            ->all();
        $referrerRequests = (Cast::int($sums->other_referrers ?? null) ?? 0)
            + (int) $referrerRows->sum(static fn (object $row): int => Cast::int($row->r ?? null) ?? 0);

        return [
            'paths' => array_values($paths),
            'pathRequests' => $covered,
            'referrers' => array_values($referrers),
            'referrerRequests' => $referrerRequests,
            'agents' => new AnalyticsAgentsData(
                Cast::int($sums->browsers ?? null) ?? 0,
                Cast::int($sums->bots ?? null) ?? 0,
                Cast::int($sums->other_agents ?? null) ?? 0,
            ),
        ];
    }

    /**
     * @param  array<int, array{requests: int, s5: int, series: array<int, int>}>  $byServer
     * @return list<AnalyticsSplitData>
     */
    private function servers(array $byServer): array
    {
        if ($byServer === []) {
            return [];
        }

        $names = Server::query()->whereKey(array_keys($byServer))->get(['id', 'public_id', 'name', 'status'])->keyBy('id');
        $out = [];

        foreach ($byServer as $id => $agg) {
            $server = $names->get($id);

            if ($server instanceof Server) {
                $out[] = new AnalyticsSplitData($server->public_id, $server->name, $agg['requests'], $this->rate($agg['s5'], $agg['requests']), array_values($agg['series']), $server->status === ServerStatus::Revoked);
            }
        }

        usort($out, static fn (AnalyticsSplitData $a, AnalyticsSplitData $b): int => [$b->requests, $a->name] <=> [$a->requests, $b->name]);

        return $out;
    }

    /**
     * @param  array<int, array{requests: int, s5: int, series: array<int, int>}>  $split
     * @param  array<int, int>  $empty
     *
     * @param-out array<int, array{requests: int, s5: int, series: array<int, int>}>  $split
     */
    private function bump(array &$split, int $key, int $i, int $requests, int $errors, array $empty): void
    {
        $agg = $split[$key] ?? ['requests' => 0, 's5' => 0, 'series' => $empty];
        $agg['requests'] += $requests;
        $agg['s5'] += $errors;
        $agg['series'][$i] = ($agg['series'][$i] ?? 0) + $requests;
        $split[$key] = $agg;
    }

    /**
     * @param  array<int, array{requests: int, s5: int, series: array<int, int>}>  $byInstance
     * @param  array<int, array{id: string, name: string}>  $names
     * @return list<AnalyticsSplitData>
     */
    private function split(array $byInstance, array $names): array
    {
        $out = [];

        foreach ($byInstance as $id => $agg) {
            $service = $names[$id] ?? null;

            if ($service !== null) {
                $out[] = new AnalyticsSplitData($service['id'], $service['name'], $agg['requests'], $this->rate($agg['s5'], $agg['requests']), array_values($agg['series']));
            }
        }

        usort($out, static fn (AnalyticsSplitData $a, AnalyticsSplitData $b): int => [$b->requests, $a->name] <=> [$a->requests, $b->name]);

        return $out;
    }

    /**
     * Names of the servers that served traffic but don't send analytics.
     *
     * @param  list<int>  $serverIds
     * @return list<string>
     */
    private function serversWithoutPaths(array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }

        return array_values(Server::query()
            ->whereKey($serverIds)
            ->orderBy('name')
            ->get(['id', 'name', 'daemon_features'])
            ->reject(static fn (Server $server): bool => $server->supportsDaemonFeature(DaemonFeature::RequestAnalytics))
            ->map(static fn (Server $server): string => $server->name)
            ->all());
    }

    private function rate(int $errors, int $requests): ?float
    {
        return $requests > 0 ? round($errors / $requests * 100, 2) : null;
    }
}
