<?php

declare(strict_types=1);

namespace App\Data\Dockerfiles;

use Spatie\LaravelData\Data;

/**
 * `dockerfile_generations.detected`: what the plan found, for the prompt and
 * the sheet (DockerfileAttempts::facts), and Railpack's error when it
 * couldn't plan.
 */
final class DockerfileDetectedData extends Data
{
    public function __construct(
        public ?string $provider = null,
        public ?string $framework = null,
        public ?string $packageManager = null,
        public ?string $runtime = null,
        public ?string $installCommand = null,
        public ?string $buildCommand = null,
        public ?string $startCommand = null,
        public ?int $port = null,
        public ?string $error = null,
        public ?string $healthPath = null,
    ) {}
}
