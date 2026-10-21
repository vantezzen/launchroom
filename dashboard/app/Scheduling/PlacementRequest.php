<?php

declare(strict_types=1);

namespace App\Scheduling;

use App\Enums\PlacementStrategy;

/**
 * What one replica set (a service instance's deployment) needs (docs/02 §2 step 3).
 */
final readonly class PlacementRequest
{
    /**
     * @param  array<string, string>  $requiredLabels
     * @param  list<int>  $previousServerIds  servers of the current deployment (preferred on ties, for image cache locality)
     * @param  list<int>|null  $allowServerIds  only these servers (null: any)
     * @param  list<int>  $denyServerIds  never these servers
     * @param  list<int>  $existingServerIds  servers already running replicas of this set that stay (rescheduling, rolling): they count for anti-affinity and capacity
     */
    public function __construct(
        public int $replicas,
        public int $memoryMb,
        public float $cpus = 1.0,
        public array $requiredLabels = [],
        public bool $stateful = false,
        /** The server holding the volume of a stateful service: never moved (docs/02 §6). */
        public ?int $pinnedServerId = null,
        public array $previousServerIds = [],
        public PlacementStrategy $strategy = PlacementStrategy::Spread,
        public ?array $allowServerIds = null,
        public array $denyServerIds = [],
        /** A preview environment: only servers allowing previews. */
        public bool $preview = false,
        /** A production environment: only servers allowing production. */
        public bool $production = false,
        /** The image's platform (built images); null: multi-platform or unknown. */
        public ?string $platform = null,
        public array $existingServerIds = [],
    ) {}
}
