<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasRangeLength;

/**
 * Time ranges of the request analytics (docs/15 §5.4): the last 24 hours
 * and 7 days in hourly buckets, the last 30 days in daily buckets (from the
 * daily rollups).
 */
enum AnalyticsRange: string
{
    use HasRangeLength;

    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    public function bucketSeconds(): int
    {
        return $this === self::Month ? 86_400 : 3_600;
    }

    public function buckets(): int
    {
        return intdiv($this->seconds(), $this->bucketSeconds());
    }
}
