<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\AnalyticsRange;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Requests the web firewall refused, for request analytics (docs/15 §5.4,
 * docs/22 §6.5). A blocked request gets a 403 from the edge and is still
 * an access log line of its route, so request metrics count it as a 4xx
 * (shown by the daemon integration test TestWafBlocksAndReports).
 * Analytics reads `waf_samples.blocked` on the same buckets and carves it
 * out of 4xx, so the 4xx share is the app's own.
 *
 * Day and Week read the 10-minute rows (kept 7 days; the oldest hour of a
 * week may already be gone), Month and earlier periods the hourly rows
 * (kept 90 days), like WafOverview.
 * `$scope` restricts `waf_samples` to ids the caller resolved inside the team.
 */
final readonly class BlockedRequests
{
    /**
     * Blocked per bucket, optionally per key column (`project_id`):
     * `[key => [bucket index => count]]`, key 0 without a column.
     *
     * @param  \Closure(Builder): mixed  $scope
     * @return array<int, array<int, int>>
     */
    public static function series(\Closure $scope, AnalyticsRange $range, CarbonImmutable $now, int $start, int $bucket, int $count, ?string $key = null): array
    {
        $from = CarbonImmutable::createFromTimestampUTC($start);
        $to = $from->addSeconds($count * $bucket);
        $q = self::rows($scope, $range, $now, $from, $to)
            ->selectRaw("extract(epoch from date_bin(make_interval(secs => ?), at, TIMESTAMPTZ 'epoch'))::bigint as b, sum(blocked) as blocked", [$bucket])
            ->groupBy('b');

        if ($key !== null) {
            $q->addSelect($key.' as k')->groupBy($key);
        }

        $out = [];

        foreach ($q->get() as $row) {
            $i = intdiv((Cast::int($row->b ?? null) ?? 0) - $start, $bucket);

            if ($i < 0 || $i >= $count) {
                continue;
            }

            $k = $key === null ? 0 : Cast::id($row->k ?? null);
            $out[$k][$i] = ($out[$k][$i] ?? 0) + (Cast::int($row->blocked ?? null) ?? 0);
        }

        return $out;
    }

    /**
     * Blocked in [from, to); null when the firewall reported nothing then.
     *
     * @param  \Closure(Builder): mixed  $scope
     */
    public static function total(\Closure $scope, AnalyticsRange $range, CarbonImmutable $now, CarbonImmutable $from, CarbonImmutable $to): ?int
    {
        $row = self::rows($scope, $range, $now, $from, $to)->selectRaw('count(*) as n, coalesce(sum(blocked), 0) as blocked')->first();

        return $row === null || Cast::id($row->n ?? null) === 0 ? null : Cast::id($row->blocked ?? null);
    }

    /**
     * Blocked per normalised path (the firewall's top paths of each window)
     * since `from`, optionally per key column: `[key => [path => count]]`.
     *
     * @param  \Closure(Builder): mixed  $scope
     * @return array<int, array<string, int>>
     */
    public static function paths(\Closure $scope, AnalyticsRange $range, CarbonImmutable $now, CarbonImmutable $from, ?string $key = null): array
    {
        $rows = DB::query()
            ->fromSub(self::rows($scope, $range, $now, $from, $now->addHour())->select($key === null ? ['paths'] : ['paths', $key.' as k']), 's')
            ->crossJoin(DB::raw('jsonb_array_elements(s.paths) as e'))
            ->selectRaw("e->>'p' as path, sum((e->>'b')::bigint) as b".($key === null ? '' : ', s.k'))
            ->groupByRaw("e->>'p'".($key === null ? '' : ', s.k'))
            ->get();
        $out = [];

        foreach ($rows as $row) {
            $blocked = Cast::int($row->b ?? null) ?? 0;

            if ($blocked > 0) {
                $out[$key === null ? 0 : Cast::id($row->k ?? null)][Cast::string($row->path ?? null)] = $blocked;
            }
        }

        return $out;
    }

    /**
     * @param  \Closure(Builder): mixed  $scope
     */
    private static function rows(\Closure $scope, AnalyticsRange $range, CarbonImmutable $now, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $hourly = $range === AnalyticsRange::Month || $from->lessThan($now->subDays(8));

        return DB::table('waf_samples')
            ->where($scope)
            ->where('resolution', $hourly ? 60 : 10)
            ->where('at', '>=', $from->toIso8601String())
            ->where('at', '<', $to->toIso8601String());
    }
}
