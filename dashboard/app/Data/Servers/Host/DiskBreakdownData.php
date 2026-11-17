<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/**
 * What takes the disk (Report.host.health.disk, measured every 30 minutes):
 * the server's volumes largest first, the WAL backlog of point-in-time
 * recovery and, on the main server, the registry.
 */
final class DiskBreakdownData extends Data
{
    /**
     * @param  list<VolumeUsageData>  $volumes
     */
    public function __construct(
        public string $measuredAt,
        public array $volumes = [],
        public ?int $walBacklogMb = null,
        public ?int $registryMb = null,
    ) {}
}
