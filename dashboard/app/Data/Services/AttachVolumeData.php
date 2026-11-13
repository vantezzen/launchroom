<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Actions\Services\AttachServiceVolume;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Mount another service's volume into this service (shared volume, D73),
 * at its own path. Same grammar as {@see AddVolumeData}; the rest is
 * checked by {@see AttachServiceVolume}.
 */
final class AttachVolumeData extends Data
{
    public function __construct(
        #[Max(255), Regex('/^\/[A-Za-z0-9._@+=,-]+(?:\/[A-Za-z0-9._@+=,-]+)*$/D')]
        public string $mountPath,
    ) {}
}
