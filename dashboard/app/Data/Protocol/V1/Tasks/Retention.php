<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use Spatie\LaravelData\Data;

/**
 * A restic forget policy (protocol Retention); zero values are left out.
 */
final class Retention extends Data
{
    public function __construct(
        public ?int $keepLast = null,
        public ?int $keepHourly = null,
        public ?int $keepDaily = null,
        public ?int $keepWeekly = null,
        public ?int $keepMonthly = null,
        public ?int $keepWithinHours = null,
    ) {}
}
