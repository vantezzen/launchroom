<?php

declare(strict_types=1);

namespace App\Support\Services;

use App\Data\Specs\ChangeItem;
use App\Models\ServiceInstance;
use App\Queries\CurrentEnvironmentSpec;
use App\Support\Changes\SpecDiff;

/**
 * Whether a service has staged changes in its environment (docs/06 §4):
 * then Deploy is the environment's "Deploy changes", never a second path
 * that would build a new version without them (D68).
 */
final readonly class StagedForService
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
    ) {}

    public function has(ServiceInstance $instance): bool
    {
        $instance->loadMissing(['environment.lastAppliedRevision', 'service']);
        $environment = $instance->environment;
        $changes = SpecDiff::between($environment->lastAppliedRevision?->spec, $this->currentSpec->get($environment));
        $id = $instance->service->public_id;

        return isset($changes->services[$id]) || array_any($changes->items, static fn (ChangeItem $item): bool => $item->serviceId === $id);
    }
}
