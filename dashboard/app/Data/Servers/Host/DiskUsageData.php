<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/** Size and free space of one filesystem (Report.host.health). */
final class DiskUsageData extends Data
{
    public function __construct(
        public int $totalMb,
        public int $freeMb,
    ) {}
}
