<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Support\Services\VolumeMountRules;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Mount another service's volume into this service (shared volume, D73),
 * at its own path. Same grammar as {@see AddVolumeData}; the rest is
 * checked by AttachServiceVolume.
 */
final class AttachVolumeData extends Data
{
    public function __construct(
        #[Max(VolumeMountRules::MAX_LENGTH), Regex(VolumeMountRules::PATH)]
        public string $mountPath,
    ) {}
}
