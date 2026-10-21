<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Enums\DeviceKind;
use App\Enums\HostingProvider;

/**
 * What DeviceClassifier detected for a server.
 */
final readonly class DeviceDetection
{
    public function __construct(
        public DeviceKind $kind,
        /** Human model name when the facts tell ("Raspberry Pi 5", "Mac mini", "Hetzner Cloud"). */
        public ?string $model = null,
        public ?HostingProvider $provider = null,
        public bool $virtualized = false,
        public ?string $virtualization = null,
    ) {}
}
