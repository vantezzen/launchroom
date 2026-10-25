<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\AnalyticsRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Which stored resolution an analytics read takes for which time (docs/15
 * §5.4): the finest rows for the last two hours (coarser rows are recomputed
 * every ten minutes), the medium rows before that and, for daily buckets,
 * the coarse rows before yesterday. Boundaries are whole hours and days, so
 * no bucket mixes two resolutions and no row is counted twice.
 */
final class AnalyticsTiers
{
    /** Request metric resolutions: 1 minute, 10 minutes, 1 day. */
    public const array REQUESTS = [1, 10, 1440];

    /** Request analytics resolutions: 10 minutes, 1 hour, 1 day. */
    public const array ANALYTICS = [10, 60, 1440];

    /**
     * Restricts a samples query to [from, to).
     *
     * @param  array{int, int, int}  $resolutions  fine, medium, coarse
     */
    public static function apply(Builder $query, AnalyticsRange $range, CarbonImmutable $now, CarbonImmutable $from, ?CarbonImmutable $to, array $resolutions): Builder
    {
        [$fine, $medium, $coarse] = $resolutions;
        $recent = $now->utc()->startOfHour()->subHours(2)->toIso8601String();
        $daily = $now->utc()->startOfDay()->subDay()->toIso8601String();
        $useDaily = $range === AnalyticsRange::Month;

        return $query
            ->where('at', '>=', $from->toIso8601String())
            ->when($to instanceof CarbonImmutable, static fn (Builder $q) => $q->where('at', '<', $to?->toIso8601String()))
            ->where(static function (Builder $q) use ($fine, $medium, $coarse, $recent, $daily, $useDaily): void {
                $q->where(static fn (Builder $w) => $w->where('resolution', $fine)->where('at', '>=', $recent))
                    ->orWhere(static fn (Builder $w) => $w->where('resolution', $medium)->where('at', '<', $recent)
                        ->when($useDaily, static fn (Builder $d) => $d->where('at', '>=', $daily)))
                    ->when($useDaily, static fn (Builder $o) => $o->orWhere(static fn (Builder $w) => $w->where('resolution', $coarse)->where('at', '<', $daily)));
            });
    }

    /**
     * The window of a range: bucket width, count, first bucket start (epoch
     * seconds), the bucket starts as ISO strings and the previous period's start.
     *
     * @return array{bucket: int, count: int, start: int, from: CarbonImmutable, previousFrom: CarbonImmutable, timestamps: list<string>}
     */
    public static function window(AnalyticsRange $range, CarbonImmutable $now): array
    {
        $bucket = $range->bucketSeconds();
        $count = $range->buckets();
        $start = intdiv($now->getTimestamp(), $bucket) * $bucket - ($count - 1) * $bucket;
        $from = CarbonImmutable::createFromTimestampUTC($start);
        $timestamps = [];

        for ($i = 0; $i < $count; $i++) {
            $timestamps[] = CarbonImmutable::createFromTimestampUTC($start + $i * $bucket)->toIso8601String();
        }

        return ['bucket' => $bucket, 'count' => $count, 'start' => $start, 'from' => $from, 'previousFrom' => $from->subSeconds($count * $bucket), 'timestamps' => $timestamps];
    }
}
