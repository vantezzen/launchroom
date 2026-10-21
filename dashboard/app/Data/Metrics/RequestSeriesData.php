<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * Request metrics of a service, an environment or an edge (docs/15 §2.1),
 * aligned with the response's `timestamps`. Buckets without traffic are 0
 * for rates and null for error rate and latency (undefined without requests).
 */
final class RequestSeriesData extends Data
{
    /**
     * @param  list<float|null>  $requestsPerSecond
     * @param  list<float|null>  $errorRatePct
     * @param  list<float|null>  $p50Ms
     * @param  list<float|null>  $p95Ms
     * @param  list<float|null>  $bytesOutPerSecond
     */
    public function __construct(
        public array $requestsPerSecond,
        public array $errorRatePct,
        public array $p50Ms,
        public array $p95Ms,
        public array $bytesOutPerSecond,
        public RequestTotalsData $totals,
        public ?RequestTotalsData $previous,
    ) {}
}
