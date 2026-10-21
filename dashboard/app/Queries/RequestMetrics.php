<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Metrics\RequestSeriesData;
use App\Data\Metrics\RequestTotalsData;
use App\Support\Metrics\Cast;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\RequestStats;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Request metrics reads (docs/15 §1.3, §2.1): counters and latency
 * histograms summed over every edge and stored row of a chart bucket,
 * percentiles derived afterwards. Selections are service instances (a
 * service, an environment) or one edge server. Callers authorize and pass
 * ids they resolved inside the team.
 *
 * Rows are plain arrays `{requests, s2, s3, s4, s5, in, out, latency}`
 * so callers can sum services into an environment before deriving values.
 *
 * @phpstan-type Agg array{requests: int, s2: int, s3: int, s4: int, s5: int, in: int, out: int, latency: list<int>|null}
 */
final readonly class RequestMetrics
{
    private const string SUMS = 'sum(requests) as requests, sum(status_2xx) as s2, sum(status_3xx) as s3, sum(status_4xx) as s4, sum(status_5xx) as s5, sum(bytes_in) as bytes_in, sum(bytes_out) as bytes_out';

    /**
     * Per bucket index, keyed by service instance (or 0 when not grouped).
     *
     * @param  array{instances?: list<int>, server?: int}  $filter
     * @return array<int, array<int, Agg>>
     */
    public function buckets(array $filter, MetricWindow $window, bool $byInstance): array
    {
        if ($this->empty($filter)) {
            return [];
        }

        $rows = $this->scoped($filter)
            ->selectRaw(($byInstance ? 'service_instance_id' : '0').' as k, '.MetricWindow::BUCKET_SQL.', '.self::SUMS.', '.RequestStats::SUM_SQL.' as latency', $window->bucketBinding())
            ->where('resolution', $window->resolution())
            ->where('at', '>=', $window->from())
            ->groupBy(...($byInstance ? ['service_instance_id', 'b'] : ['b']))
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $i = $window->index($row->b ?? null);

            if ($i !== null) {
                $out[Cast::id($row->k ?? null)][$i] = $this->agg($row);
            }
        }

        return $out;
    }

    /**
     * Sums over [from, to) at the window's resolution, keyed like buckets().
     *
     * @param  array{instances?: list<int>, server?: int}  $filter
     * @return array<int, Agg>
     */
    public function totals(array $filter, MetricWindow $window, bool $byInstance, bool $previous = false): array
    {
        if ($this->empty($filter)) {
            return [];
        }

        $from = CarbonImmutable::createFromTimestampUTC($window->start);
        $length = $window->count * $window->bucket;
        $query = $this->scoped($filter)
            ->selectRaw(($byInstance ? 'service_instance_id' : '0').' as k, '.self::SUMS.', '.RequestStats::SUM_SQL.' as latency')
            ->where('resolution', $window->resolution())
            ->when($byInstance, static fn (Builder $q) => $q->groupBy('service_instance_id'));

        if ($previous) {
            $query->where('at', '>=', $from->subSeconds($length)->toIso8601String())->where('at', '<', $from->toIso8601String());
        } else {
            $query->where('at', '>=', $from->toIso8601String());
        }

        $out = [];

        foreach ($query->get() as $row) {
            if (Cast::int($row->requests ?? null) === null) {
                continue; // no rows: the ungrouped sum is one row of NULLs
            }

            $out[Cast::id($row->k ?? null)] = $this->agg($row);
        }

        return $out;
    }

    /**
     * Requests/s per bucket for MetricWindow::visibleCount(): 0 where a
     * bucket had no traffic, null for the newest bucket while it has none.
     *
     * @param  array<int, Agg>  $buckets
     * @return array<int, float|null>
     */
    public static function rates(array $buckets, MetricWindow $window): array
    {
        $out = [];

        for ($i = 0; $i < $window->count; $i++) {
            $agg = $buckets[$i] ?? null;
            $out[$i] = $agg === null ? ($i === $window->count - 1 ? null : 0.0) : round($agg['requests'] / self::seconds($window, $i), 4);
        }

        return $out;
    }

    /**
     * Seconds a bucket covers so far: the full width, except for the newest
     * bucket, which only holds the minutes completed since it started (a
     * rate over the full width would dip at the right edge of every chart).
     */
    public static function seconds(MetricWindow $window, int $i): int
    {
        if ($i < $window->count - 1) {
            return $window->bucket;
        }

        $start = $window->start + $i * $window->bucket;
        $completed = intdiv($window->now->getTimestamp(), 60) * 60 - $start;

        return max($window->resolution() * 60, min($window->bucket, $completed));
    }

    /**
     * The Data of one selection; null when it had no request in the range.
     *
     * @param  array<int, Agg>  $buckets
     * @param  Agg|null  $total
     * @param  Agg|null  $previous
     */
    public static function data(array $buckets, ?array $total, ?array $previous, MetricWindow $window, int $count): ?RequestSeriesData
    {
        if ($total === null || $total['requests'] <= 0) {
            return null;
        }

        $rps = $errors = $p50 = $p95 = $bytes = [];
        $rates = self::rates($buckets, $window);

        for ($i = 0; $i < $count; $i++) {
            $agg = $buckets[$i] ?? null;
            $rps[] = $rates[$i] ?? null;
            $bytes[] = $agg === null ? (($rates[$i] ?? null) === null ? null : 0.0) : round($agg['out'] / self::seconds($window, $i), 1);
            $errors[] = $agg === null || $agg['requests'] <= 0 ? null : round($agg['s5'] / $agg['requests'] * 100, 2);
            $p50[] = $agg === null ? null : RequestStats::quantile($agg['latency'] ?? [], 0.5);
            $p95[] = $agg === null ? null : RequestStats::quantile($agg['latency'] ?? [], 0.95);
        }

        $seconds = $window->count * $window->bucket;

        return new RequestSeriesData(
            $rps,
            array_filter($errors, static fn (?float $v): bool => $v !== null) === [] ? [] : $errors,
            array_filter($p50, static fn (?float $v): bool => $v !== null) === [] ? [] : $p50,
            array_filter($p95, static fn (?float $v): bool => $v !== null) === [] ? [] : $p95,
            $bytes,
            self::totalsData($total, $seconds),
            $previous === null || $previous['requests'] <= 0 ? null : self::totalsData($previous, $seconds),
        );
    }

    /**
     * Element-wise sum of several selections (services → environment).
     *
     * @param  list<Agg|null>  $aggs
     * @return Agg|null
     */
    public static function sum(array $aggs): ?array
    {
        $out = null;

        foreach ($aggs as $agg) {
            if ($agg === null) {
                continue;
            }

            $out = $out === null ? $agg : [
                'requests' => $out['requests'] + $agg['requests'],
                's2' => $out['s2'] + $agg['s2'],
                's3' => $out['s3'] + $agg['s3'],
                's4' => $out['s4'] + $agg['s4'],
                's5' => $out['s5'] + $agg['s5'],
                'in' => $out['in'] + $agg['in'],
                'out' => $out['out'] + $agg['out'],
                'latency' => RequestStats::add($out['latency'], $agg['latency']),
            ];
        }

        return $out;
    }

    /**
     * @param  Agg  $agg
     */
    private static function totalsData(array $agg, int $seconds): RequestTotalsData
    {
        return new RequestTotalsData(
            $agg['requests'], $agg['s2'], $agg['s3'], $agg['s4'], $agg['s5'], $agg['in'], $agg['out'],
            round($agg['requests'] / max(1, $seconds), 4),
            $agg['requests'] > 0 ? round($agg['s5'] / $agg['requests'] * 100, 2) : null,
            RequestStats::quantile($agg['latency'] ?? [], 0.5),
            RequestStats::quantile($agg['latency'] ?? [], 0.95),
        );
    }

    /**
     * @return Agg
     */
    private function agg(object $row): array
    {
        return [
            'requests' => Cast::int($row->requests ?? null) ?? 0,
            's2' => Cast::int($row->s2 ?? null) ?? 0,
            's3' => Cast::int($row->s3 ?? null) ?? 0,
            's4' => Cast::int($row->s4 ?? null) ?? 0,
            's5' => Cast::int($row->s5 ?? null) ?? 0,
            'in' => Cast::int($row->bytes_in ?? null) ?? 0,
            'out' => Cast::int($row->bytes_out ?? null) ?? 0,
            'latency' => RequestStats::parse($row->latency ?? null),
        ];
    }

    /**
     * @param  array{instances?: list<int>, server?: int}  $filter
     */
    private function empty(array $filter): bool
    {
        return ! isset($filter['server']) && ($filter['instances'] ?? []) === [];
    }

    /**
     * @param  array{instances?: list<int>, server?: int}  $filter
     */
    private function scoped(array $filter): Builder
    {
        $query = DB::table('request_metric_samples');

        if (isset($filter['instances'])) {
            $query->whereIn('service_instance_id', $filter['instances']);
        }

        if (isset($filter['server'])) {
            $query->where('server_id', $filter['server']);
        }

        return $query;
    }
}
