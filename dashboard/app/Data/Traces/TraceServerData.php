<?php

declare(strict_types=1);

namespace App\Data\Traces;

use App\Data\Servers\DeviceData;
use App\Enums\ServerStatus;
use Spatie\LaravelData\Data;

/**
 * A server on a traced request's path (docs/23 §7): drawn with its device.
 */
final class TraceServerData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ServerStatus $status,
        public DeviceData $device,
        public string $href,
    ) {}
}
