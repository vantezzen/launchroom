<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * Placement settings and current load of a server (server page): labels,
 * the preview/production flags, link measurements, what the scheduler has reserved there and
 * the replicas it runs.
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
        /** Memory reserved by serving replicas (their limits). */
        public int $allocatedMemoryMb,
        /** Memory the scheduler may hand out (total × overcommit − reserved); null when unknown. */
        public ?int $schedulableMemoryMb,
        public float $allocatedCpus,
        public ?float $schedulableCpus,
        public array $workloads,
        /** Measure private-network links to its peers (docs/05 §2.1). */
        public bool $measureLinks = true,
    ) {}
}
