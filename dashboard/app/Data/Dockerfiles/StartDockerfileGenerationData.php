<?php

declare(strict_types=1);

namespace App\Data\Dockerfiles;

use App\Enums\Dockerfiles\DockerfileGenerationTrigger;
use Spatie\LaravelData\Data;

/**
 * Why the developer opened "Generate a Dockerfile" (docs/20 §3).
 */
final class StartDockerfileGenerationData extends Data
{
    public function __construct(
        public DockerfileGenerationTrigger $trigger = DockerfileGenerationTrigger::OnDemand,
    ) {}
}
