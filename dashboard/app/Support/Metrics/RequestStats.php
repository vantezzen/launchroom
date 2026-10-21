<?php

declare(strict_types=1);

namespace App\Support\Metrics;

/**
 * Derived request statistics (docs/15 §1.3). launchroomd counts every
 * non-1xx request's duration into 15 fixed buckets (upper bounds in ms,
 * inclusive, plus an overflow bucket); histograms add up exactly across
 * servers, minutes and rollups, so percentiles are computed only at read
 * time from the summed counts, linear inside the bucket that holds them.
 */
final class RequestStats
{
    public const int BUCKETS = 15;

    /** Upper bounds (ms) of the first 14 buckets; the 15th holds everything slower. */
    public const array BOUNDS_MS = [1, 2, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000];

    /**
     * The q-quantile (0–1) in ms; null without counts. The overflow bucket
     * reads as its lower bound (30 s: "slower than 30 s").
     *
     * @param  list<int>  $histogram
     */
    public static function quantile(array $histogram, float $q): ?float
    {
        $total = array_sum($histogram);

        if ($total <= 0) {
            return null;
        }

        $rank = max(min($q, 1.0), 0.0) * $total;
        $seen = 0;

        foreach ($histogram as $i => $count) {
            if ($count <= 0) {
                continue;
            }

            if ($seen + $count >= $rank) {
                if ($i >= count(self::BOUNDS_MS)) {
                    return (float) self::BOUNDS_MS[count(self::BOUNDS_MS) - 1];
                }

                $lower = $i === 0 ? 0.0 : (float) self::BOUNDS_MS[$i - 1];
                $upper = (float) self::BOUNDS_MS[$i];

                return round($lower + ($upper - $lower) * max(0.0, $rank - $seen) / $count, 1);
            }

            $seen += $count;
        }

        return (float) self::BOUNDS_MS[count(self::BOUNDS_MS) - 1];
    }

    /**
     * Element-wise sum; null histograms are skipped.
     *
     * @param  list<int>|null  $into
     * @param  list<int>|null  $add
     * @return list<int>|null
     */
    public static function add(?array $into, ?array $add): ?array
    {
        if ($add === null) {
            return $into;
        }

        if ($into === null) {
            return $add;
        }

        $out = [];

        for ($i = 0; $i < self::BUCKETS; $i++) {
            $out[] = ($into[$i] ?? 0) + ($add[$i] ?? 0);
        }

        return $out;
    }

    /**
     * A Postgres bigint[] text value ("{1,2,…}") as a list; null otherwise.
     *
     * @return list<int>|null
     */
    public static function parse(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/^\{(\d+(,\d+){14})\}$/D', $value, $m) !== 1) {
            return null;
        }

        return array_map(intval(...), explode(',', $m[1]));
    }

    /**
     * A list as a Postgres array literal (for upserts); null stays null.
     *
     * @param  list<int>|null  $histogram
     */
    public static function literal(?array $histogram): ?string
    {
        return $histogram === null ? null : '{'.implode(',', $histogram).'}';
    }

    /**
     * SQL of the element-wise sum of `latency` (all 15 buckets) as one
     * bigint[]; NULL when no row of the group had a histogram. Written out
     * so it stays a literal (query builders only take literal SQL).
     */
    public const string SUM_SQL = 'CASE WHEN count(latency) = 0 THEN NULL ELSE ARRAY[coalesce(sum(latency[1]), 0), coalesce(sum(latency[2]), 0), coalesce(sum(latency[3]), 0), coalesce(sum(latency[4]), 0), coalesce(sum(latency[5]), 0), coalesce(sum(latency[6]), 0), coalesce(sum(latency[7]), 0), coalesce(sum(latency[8]), 0), coalesce(sum(latency[9]), 0), coalesce(sum(latency[10]), 0), coalesce(sum(latency[11]), 0), coalesce(sum(latency[12]), 0), coalesce(sum(latency[13]), 0), coalesce(sum(latency[14]), 0), coalesce(sum(latency[15]), 0)]::bigint[] END';
}
