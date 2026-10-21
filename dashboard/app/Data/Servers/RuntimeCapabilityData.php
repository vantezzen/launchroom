<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\Servers\CapabilityState;
use App\Enums\Servers\RuntimeCapability;
use Spatie\LaravelData\Data;

/**
 * One capability of a server's runtime (Report.host.runtime.capabilities,
 * D49), shown on the server's Network tab.
 */
final class RuntimeCapabilityData extends Data
{
    public function __construct(
        public RuntimeCapability $name,
        public CapabilityState $state,
        /** Secret-free explanation from launchroomd (at most 512 characters). */
        public ?string $message = null,
    ) {}
}
