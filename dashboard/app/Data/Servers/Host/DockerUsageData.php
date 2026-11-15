<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/** Disk Docker uses for images and containers. */
final class DockerUsageData extends Data
{
    public function __construct(
        public int $imagesMb,
        public int $imagesReclaimableMb,
        public int $containersMb,
    ) {}
}
