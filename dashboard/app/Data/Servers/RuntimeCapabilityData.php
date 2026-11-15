<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\Servers\CapabilityReason;
use App\Enums\Servers\CapabilityState;
use App\Enums\Servers\RuntimeCapability;
use Spatie\LaravelData\Data;

/**
 * One capability of a server's runtime (Report.host.runtime.capabilities,
 * D49), as stored.
 */
final class RuntimeCapabilityData extends Data
{
    public function __construct(
        public RuntimeCapability $name,
        public CapabilityState $state,
        /** The secret-free facts behind the reason (at most 512 characters); older launchroomd sent a whole sentence here and no reason. */
        public ?string $message = null,
        public ?CapabilityReason $reason = null,
    ) {}
}
