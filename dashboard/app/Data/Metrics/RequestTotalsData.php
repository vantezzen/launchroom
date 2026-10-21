<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * Request totals over a period (docs/15 §2.1): the chart range, or the same
 * length of time just before it (`previous`, for deltas). Percentiles come
 * from the summed latency histogram; null without timed requests.
 */
final class RequestTotalsData extends Data
{
    public function __construct(
        public int $requests,
        public int $status2xx,
        public int $status3xx,
        public int $status4xx,
        public int $status5xx,
        public int $bytesIn,
        public int $bytesOut,
        /** Average over the period. */
        public float $requestsPerSecond,
        /** 5xx share in percent; null without requests. */
        public ?float $errorRatePct,
        public ?float $p50Ms,
        public ?float $p95Ms,
    ) {}
}
