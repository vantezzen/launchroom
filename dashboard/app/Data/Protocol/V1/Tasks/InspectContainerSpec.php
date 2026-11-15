<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;

/**
 * Reads a workload container's changes and processes (protocol
 * InspectContainerSpec, docs/03 §13.2).
 */
final class InspectContainerSpec extends TaskSpec
{
    /**
     * @param  list<string>|null  $maskEnv
     */
    public function __construct(
        public string $workload,
        public ?array $maskEnv = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::InspectContainer;
    }

    public function requiredFeatures(): array
    {
        return [DaemonFeature::ContainerInspection];
    }
}
