<?php

declare(strict_types=1);

namespace App\Data\Services;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * A named volume for a service (staged, docs/06 §4): its mount path inside
 * the container. The grammar matches launchroomd's ContainerPath; the rest
 * (clean path, no /proc, /sys, /dev) is checked by {@see AddServiceVolume}.
 */
final class AddVolumeData extends Data
{
    public function __construct(
        #[Max(255), Regex('/^\/[A-Za-z0-9._@+=,-]+(?:\/[A-Za-z0-9._@+=,-]+)*$/D')]
        public string $mountPath,
    ) {}
}
