<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

/**
 * Coarse client classes (docs/15 §5.1): browsers (a "Mozilla" user agent
 * without a bot keyword), bots and crawlers, everything else.
 */
final class AnalyticsAgentsData extends Data
{
    public function __construct(
        public int $browsers,
        public int $bots,
        public int $other,
    ) {}
}
