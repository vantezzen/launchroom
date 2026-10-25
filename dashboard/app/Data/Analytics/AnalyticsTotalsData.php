<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

/**
 * Request counts over the range and the same length of time before it
 * (docs/15 §5.4). Requests include 1xx (WebSocket upgrades), which are in
 * no status class.
 */
final class AnalyticsTotalsData extends Data
{
    public function __construct(
        public int $requests,
        public int $status2xx,
        public int $status3xx,
        public int $status4xx,
        public int $status5xx,
        /** 5xx share in percent; null without requests. */
        public ?float $errorRatePct,
        /** Requests of the previous period; null without any. */
        public ?int $previousRequests,
        public ?float $previousErrorRatePct,
    ) {}
}
