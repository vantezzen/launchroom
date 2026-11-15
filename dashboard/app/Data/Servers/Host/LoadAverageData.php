<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/** The kernel's 1/5/15-minute load averages. */
final class LoadAverageData extends Data
{
    public function __construct(
        public float $load1,
        public float $load5,
        public float $load15,
    ) {}
}
