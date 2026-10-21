<?php

declare(strict_types=1);

namespace App\Data\Specs;

use Spatie\LaravelData\Data;

final class VolumeSpec extends Data
{
    public function __construct(
        public string $id,
        public string $mountPath,
    ) {}
}
