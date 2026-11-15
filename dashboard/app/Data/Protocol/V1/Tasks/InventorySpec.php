<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;

/**
 * Lists the packages and runtimes of an image (protocol InventorySpec,
 * docs/03 §13.1).
 */
final class InventorySpec extends TaskSpec
{
    public function __construct(
        public string $image,
        public string $platform,
        public ?string $service = null,
        public ?InventorySource $source = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::Inventory;
    }

    public function requiredFeatures(): array
    {
        return $this->source?->repo->needsGitProviders() === true ? [DaemonFeature::Inventory, DaemonFeature::GitProviders] : [DaemonFeature::Inventory];
    }

    public function platform(): string
    {
        return $this->platform;
    }
}
