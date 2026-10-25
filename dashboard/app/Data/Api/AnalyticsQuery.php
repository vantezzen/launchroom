<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\AnalyticsRange;
use Spatie\LaravelData\Data;

/**
 * `?range=24h|7d|30d` of the analytics endpoints (docs/15 §5.4); default 7d.
 */
final class AnalyticsQuery extends Data
{
    public function __construct(
        public AnalyticsRange $range = AnalyticsRange::Week,
    ) {}
}
