<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * `servers.facts.hardware`: what launchroomd read about the machine
 * (docs/design/direction.md §7.3), whitelisted from the protocol. Input for
 * App\Support\Servers\DeviceClassifier; no serials or UUIDs ever arrive.
 */
final class HardwareFactsData extends Data
{
    public function __construct(
        public ?string $cpuModel = null,
        public ?string $cpuVendor = null,
        public ?DmiFactsData $dmi = null,
        public ?string $deviceTreeModel = null,
        /** kvm, qemu, vmware, virtualbox, xen, hyperv, parallels, apple, bhyve, container, other; null = none detected. */
        public ?string $virtualization = null,
        public ?string $osName = null,
        public ?string $kernel = null,
        public ?string $timezone = null,
        /** The machine has a system battery (laptops); null = none reported. */
        public ?bool $battery = null,
        /** The model the installer read on the host ("Mac mini", "MacBook Pro"), D53; display only. */
        public ?string $hostModel = null,
    ) {}
}
