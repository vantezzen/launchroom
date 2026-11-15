<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Support\Services\VolumeMountRules;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * A new mount path for a service volume (staged, docs/14 §9.12). Same
 * grammar as {@see AddVolumeData}; the rest is checked by
 * UpdateServiceVolume.
 */
final class UpdateVolumeData extends Data
{
    public function __construct(
        #[Max(VolumeMountRules::MAX_LENGTH), Regex(VolumeMountRules::PATH)]
        public string $mountPath,
    ) {}
}
