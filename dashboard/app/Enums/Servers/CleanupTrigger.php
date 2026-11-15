<?php

declare(strict_types=1);

namespace App\Enums\Servers;

/**
 * Why launchroomd last pruned unused images (Report.host.health.cleanup).
 */
enum CleanupTrigger: string
{
    case Schedule = 'schedule';
    case DiskPressure = 'disk_pressure';
}
