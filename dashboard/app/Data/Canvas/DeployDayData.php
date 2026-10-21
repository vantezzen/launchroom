<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * Deployments of one day (UTC) for the 14-day deploy bars (direction.md §5.3).
 */
final class DeployDayData extends Data
{
    public function __construct(
        /** Y-m-d (UTC). */
        public string $date,
        public int $total,
        public int $failed,
    ) {}
}
