<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use Spatie\LaravelData\Data;

/**
 * One replica placed on a server (server page, "Workloads").
 */
final class ServerWorkloadData extends Data
{
    public function __construct(
        public string $workloadId,
        public string $serviceId,
        public string $serviceName,
        public string $environmentId,
        public string $environmentName,
        public string $projectId,
        public string $projectName,
        public string $deploymentId,
        /** Replica slot (0-based). */
        public int $slot,
        public WorkloadRole $role,
        public WorkloadPhase $phase,
        /** Part of the service's active deployment (else: rolling out or draining). */
        public bool $active,
        public int $memoryMb,
        public float $cpus,
    ) {}
}
