<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * Usage of one service in one environment (summed over its replicas, or
 * over the replicas on one server for the server page), aligned with the
 * response's `timestamps`. `replicas` is filled only when the request asked
 * for a single service (service panel Metrics tab).
 */
final class ServiceUsageSeriesData extends Data
{
    /**
     * @param  list<float|null>  $cpuCores
     * @param  list<float|null>  $memMb
     * @param  list<ReplicaUsageSeriesData>  $replicas
     */
    public function __construct(
        public string $serviceId,
        public string $serviceName,
        public string $environmentId,
        public string $environmentName,
        public string $projectId,
        public string $projectName,
        /** Data palette slot of the project (0–7). */
        public int $colorSlot,
        public array $cpuCores,
        public array $memMb,
        /** Newest minute (≤ 5 minutes old), summed over replicas; null when none. */
        public ?float $currentCpuCores,
        public ?int $currentMemMb,
        public array $replicas = [],
        /** Public traffic through the edges (docs/15 §2.1); null without requests in the range. */
        public ?RequestSeriesData $requests = null,
    ) {}
}
