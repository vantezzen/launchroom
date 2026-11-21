<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use App\Data\Canvas\NodeStatusData;
use Spatie\LaravelData\Data;

/**
 * A service with an address that doesn't serve (D109): the empty Analytics
 * page shows it with its status (D108) and that status's remedy.
 */
final class TrafficBlockerData extends Data
{
    public function __construct(
        public string $serviceId,
        public string $name,
        public string $logo,
        public string $projectId,
        public string $projectName,
        public string $environmentId,
        public string $environmentName,
        public NodeStatusData $status,
    ) {}
}
