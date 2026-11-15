<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\Servers\CapabilityState;
use App\Enums\Servers\RuntimeCapability;
use Spatie\LaravelData\Data;

/**
 * One capability row on the server's Network tab, worded by the dashboard
 * from the reported reason and facts.
 */
final class CapabilityRowData extends Data
{
    public function __construct(
        public RuntimeCapability $name,
        public CapabilityState $state,
        public ?string $explanation,
    ) {}
}
