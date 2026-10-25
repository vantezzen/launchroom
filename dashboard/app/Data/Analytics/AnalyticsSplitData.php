<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

/**
 * One server or service of the analytics split (docs/15 §5.4): its requests
 * over the range, error rate and requests per bucket.
 */
final class AnalyticsSplitData extends Data
{
    /**
     * @param  list<int>  $requestsSeries
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $requests,
        public ?float $errorRatePct,
        public array $requestsSeries,
        /** A server that was removed (revoked) since; its name may now belong to another server. */
        public bool $removed = false,
    ) {}
}
