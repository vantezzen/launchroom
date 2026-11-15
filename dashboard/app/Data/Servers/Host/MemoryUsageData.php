<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/** Total memory and MemAvailable (the page cache counts as free). */
final class MemoryUsageData extends Data
{
    public function __construct(
        public int $totalMb,
        public int $availableMb,
    ) {}
}
