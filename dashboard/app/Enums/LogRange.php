<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasRangeLength;

/**
 * Time ranges of the log explorer (docs/08 §12): the last 15 minutes up to
 * the last 30 days, the longest log retention.
 */
enum LogRange: string
{
    use HasRangeLength;

    case FifteenMinutes = '15m';
    case Hour = '1h';
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';
}
