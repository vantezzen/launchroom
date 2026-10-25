<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

/**
 * A referrer host (never a full URL) and the requests it sent (docs/15 §5.1).
 */
final class AnalyticsReferrerData extends Data
{
    public function __construct(
        public string $host,
        public int $requests,
    ) {}
}
