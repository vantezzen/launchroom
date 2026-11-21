<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasRangeLength;

/**
 * Time ranges of the request analytics (docs/15 §5.4): the last hour in
 * 10-minute buckets (the finest analytics and firewall rows), the last 24
 * hours and 7 days in hourly buckets, the last 30 days in daily buckets
 * (from the daily rollups).
 */
enum AnalyticsRange: string
{
    use HasRangeLength;

    case Hour = '1h';
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    public function bucketSeconds(): int
    {
        return match ($this) {
            self::Hour => 600,
            self::Day, self::Week => 3_600,
            self::Month => 86_400,
        };
    }

    public function buckets(): int
    {
        return intdiv($this->seconds(), $this->bucketSeconds());
    }
}
