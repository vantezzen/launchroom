<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Actions\Services\UpdateServiceVolume;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * A new mount path for a service volume (staged, docs/14 §9.12). Same
 * grammar as {@see AddVolumeData}; the rest is checked by
 * {@see UpdateServiceVolume}.
 */
final class UpdateVolumeData extends Data
{
    public function __construct(
        #[Max(255), Regex('/^\/[A-Za-z0-9._@+=,-]+(?:\/[A-Za-z0-9._@+=,-]+)*$/D')]
        public string $mountPath,
    ) {}
}
