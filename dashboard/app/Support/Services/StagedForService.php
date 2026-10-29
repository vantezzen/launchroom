<?php

declare(strict_types=1);

namespace App\Support\Services;

use App\Data\Specs\ChangeItem;
use App\Data\Specs\EnvironmentSpec;
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

    /**
     * @param  EnvironmentSpec|null  $current  the instance environment's current spec, when the caller already built it
     */
    public function has(ServiceInstance $instance, ?EnvironmentSpec $current = null): bool
    {
        $instance->loadMissing(['environment.lastAppliedRevision', 'service']);
        $environment = $instance->environment;
        $changes = SpecDiff::between($environment->lastAppliedRevision?->spec, $current ?? $this->currentSpec->get($environment));
        $id = $instance->service->public_id;

        return isset($changes->services[$id]) || array_any($changes->items, static fn (ChangeItem $item): bool => $item->serviceId === $id);
    }
}
