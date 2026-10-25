<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

/**
 * One normalised path of the top routes (docs/15 §5.1): ids appear as
 * `:id`, there is never a query string.
 */
final class AnalyticsPathData extends Data
{
    public function __construct(
        public string $path,
        public int $requests,
        public int $status4xx,
        public int $status5xx,
    ) {}
}
