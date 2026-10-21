<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * The read-only Docker view under Advanced (docs/08 §3.7): generated names
 * and identifiers for debugging. Never secrets.
 */
final class AdvancedServiceData extends Data
{
    /**
     * @param  list<WorkloadData>  $workloads
     * @param  list<CapabilityOptionData>  $capabilityOptions
     */
    public function __construct(
        public string $serviceId,
        public string $instanceId,
        public string $environmentId,
        public string $networkName,
        public ?string $imageRepository,
        public ?string $image,
        public ?string $activeDeploymentId,
        public array $workloads,
        /** Capabilities Advanced may grant (launchroomd's allow-list). */
        public array $capabilityOptions = [],
    ) {}
}
