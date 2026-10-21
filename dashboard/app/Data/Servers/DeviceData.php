<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\DeviceKind;
use App\Enums\HostingProvider;
use App\Models\Server;
use App\Support\Servers\DeviceClassifier;
use Spatie\LaravelData\Data;

/**
 * The device illustration and provider of a server (docs/design/direction.md
 * §7). `kind` is what the UI draws: the user's pick if there is one, else
 * the detected device.
 */
final class DeviceData extends Data
{
    public function __construct(
        public DeviceKind $kind,
        public DeviceKind $detected,
        /** The user picked the device ("Looks like"). */
        public bool $overridden,
        /** Human model name when known ("Raspberry Pi 5", "Mac mini", "Hetzner Cloud"). */
        public ?string $model,
        public ?HostingProvider $provider,
        public ?string $providerName,
        public bool $virtualized,
    ) {}

    public static function fromServer(Server $server): self
    {
        $detection = DeviceClassifier::classify($server->facts->hardware);
        $override = $server->device_override;

        return new self(
            $override ?? $detection->kind,
            $detection->kind,
            $override instanceof DeviceKind,
            $detection->model,
            $detection->provider,
            $detection->provider?->label(),
            $detection->virtualized,
        );
    }
}
