<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use App\Enums\MetricRange;
use Carbon\CarbonImmutable;

/**
 * The buckets of one metrics response: `count` buckets of `bucket` seconds,
 * aligned to the Unix epoch, ending with the bucket that holds `now`.
 * Series are lists aligned with timestamps(); a series without any value is
 * returned as `[]` so the UI can hide its chart.
 */
final readonly class MetricWindow
{
    public int $bucket;

    public int $count;

    public int $start;

    public function __construct(public MetricRange $range, public CarbonImmutable $now)
    {
        $this->bucket = $range->bucketSeconds();
        $this->count = intdiv($range->seconds(), $this->bucket);
        $this->start = intdiv($now->getTimestamp(), $this->bucket) * $this->bucket - ($this->count - 1) * $this->bucket;
    }

    public function resolution(): int
    {
        return $this->range->resolution();
    }

    public function from(): string
    {
        return CarbonImmutable::createFromTimestampUTC($this->start)->toIso8601String();
    }

    /**
     * SQL of the bucket start (epoch seconds) of `at`, aliased `b`; bind bucketBinding().
     */
    public const string BUCKET_SQL = "extract(epoch from date_bin(make_interval(secs => ?), at, TIMESTAMPTZ 'epoch'))::bigint as b";

    /**
     * @return list<int>
     */
    public function bucketBinding(): array
    {
        return [$this->bucket];
    }

    public function index(mixed $bucketStart): ?int
    {
        if (! is_numeric($bucketStart)) {
            return null;
        }

        $i = intdiv((int) $bucketStart - $this->start, $this->bucket);

        return $i >= 0 && $i < $this->count ? $i : null;
    }

    /**
     * @return list<string>
     */
    public function timestamps(int $count): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = CarbonImmutable::createFromTimestampUTC($this->start + $i * $this->bucket)->toIso8601String();
        }

        return $out;
    }

    /**
     * @return array<int, float|null>
     */
    public function empty(): array
    {
        return array_fill(0, $this->count, null);
    }

    /**
     * How many buckets to return: all, minus the newest one when no series
     * has a value in it yet (minutes arrive after they complete).
     *
     * @param  iterable<array<int, float|null>>  $series
     */
    public function visibleCount(iterable $series): int
    {
        foreach ($series as $values) {
            if (($values[$this->count - 1] ?? null) !== null) {
                return $this->count;
            }
        }

        return $this->count - 1;
    }

    /**
     * Cuts a series to `count` buckets; a series without values becomes [].
     *
     * @param  array<int, float|null>  $values
     * @return list<float|null>
     */
    public static function finish(array $values, int $count): array
    {
        $values = array_slice(array_values($values), 0, $count);

        return array_filter($values, static fn (?float $v): bool => $v !== null) === [] ? [] : $values;
    }
}
