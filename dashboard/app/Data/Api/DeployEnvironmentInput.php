<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * `POST /environments/{environment}/deploy`: apply the staged changes.
 * `stage`: build and start, route only on Promote (docs/06 §6.5).
 */
final class DeployEnvironmentInput extends Data
{
    public function __construct(
        #[Max(500)]
        public ?string $message = null,
        public bool $stage = false,
    ) {}
}
