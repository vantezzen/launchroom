<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * `POST /environments/{environment}/config/validate|diff`: a launchroom.json
 * to check against the environment (docs/18 §7). Never stored.
 */
final class ConfigContentInput extends Data
{
    public function __construct(
        /** The file's text, as it is in the repository (≤ 256 KiB). */
        #[Max(262144)]
        public string $content,
    ) {}
}
