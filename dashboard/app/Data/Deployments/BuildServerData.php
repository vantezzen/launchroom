<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Data\Servers\DeviceData;
use Spatie\LaravelData\Data;

/** The server assigned to build this deployment's image. */
final class BuildServerData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public DeviceData $device,
    ) {}
}
