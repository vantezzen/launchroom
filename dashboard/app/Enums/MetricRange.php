<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Time ranges of the usage charts. Each picks the stored resolution that
 * still covers it (1-minute rows live 48 h, 10-minute rows 30 days, daily
 * rows a year) and a bucket width that keeps every series at ≤ 120 points.
 */
enum MetricRange: string
{
    case Hour = '1h';
    case SixHours = '6h';
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';
    case Quarter = '90d';
    case Year = '1y';

    public function seconds(): int
    {
        return match ($this) {
            self::Hour => 3_600,
            self::SixHours => 6 * 3_600,
            self::Day => 86_400,
            self::Week => 7 * 86_400,
            self::Month => 30 * 86_400,
            self::Quarter => 90 * 86_400,
            self::Year => 365 * 86_400,
        };
    }

    /**
     * Stored resolution (minutes) the range reads.
     */
    public function resolution(): int
    {
        return match ($this) {
            self::Hour, self::SixHours, self::Day => 1,
            self::Week, self::Month => 10,
            self::Quarter, self::Year => 1440,
        };
    }

    public function bucketSeconds(): int
    {
        return match ($this) {
            self::Hour => 60,
            self::SixHours => 180,
            self::Day => 720,
            self::Week => 5_400,
            self::Month => 21_600,
            self::Quarter => 86_400,
            self::Year => 4 * 86_400,
        };
    }
}
