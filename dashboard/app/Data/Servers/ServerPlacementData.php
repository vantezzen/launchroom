<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * Placement settings and current load of a server (server page): labels,
 * the preview/production flags, link measurements, the replicas it runs
 * with their limits, and the memory new services can still get there.
 */
final class ServerPlacementData extends Data
{
    /**
     * @param  array<string, string>  $labels
     * @param  list<ServerWorkloadData>  $workloads
     */
    public function __construct(
        public array $labels,
        public bool $allowPreviews,
        public bool $allowProduction,
        public bool $canUpdate,
        /** Memory limits of the serving replicas (caps, not reservations: they may add up to more than the server has, D72). */
        public int $allocatedMemoryMb,
        /** Memory a new replica can still get here (reported free minus headroom); null when unknown. */
        public ?int $freeMemoryMb,
        /** CPU limits of the serving replicas (CPU is shared, never reserved). */
        public float $allocatedCpus,
        public array $workloads,
        /** Measure private-network links to its peers (docs/05 §2.1). */
        public bool $measureLinks = true,
    ) {}
}
