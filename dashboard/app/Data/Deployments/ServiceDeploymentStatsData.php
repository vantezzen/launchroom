<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * Deployment stats of one service in one environment (a service instance).
 */
final class ServiceDeploymentStatsData extends Data
{
    public function __construct(
        public string $serviceId,
        public string $environmentId,
        public ?int $medianDurationSeconds,
        public int $weekTotal,
        public int $weekFailed,
    ) {}
}
