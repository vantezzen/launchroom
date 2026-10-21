<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Servers\DeviceData;
use App\Enums\ServerStatus;
use Spatie\LaravelData\Data;

final class VolumeData extends Data
{
    public function __construct(
        public string $id,
        public string $mountPath,
        public ?int $sizeBytes,
        public ?string $server,
        /** Generated host-side name (Advanced view only). */
        public string $dockerName,
        /** The server holding it (public id), its device and status, once placed. */
        public ?string $serverId = null,
        public ?DeviceData $serverDevice = null,
        public ?ServerStatus $serverStatus = null,
    ) {}
}
