<?php

declare(strict_types=1);

namespace App\Data\Dockerfiles;

use Spatie\LaravelData\Data;

/**
 * The team's switch for "Generate a Dockerfile" (docs/20 §1).
 */
final class TeamDockerfileGenerationData extends Data
{
    public function __construct(
        public bool $enabled,
    ) {}
}
