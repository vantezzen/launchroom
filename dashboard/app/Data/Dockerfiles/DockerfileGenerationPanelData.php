<?php

declare(strict_types=1);

namespace App\Data\Dockerfiles;

use Spatie\LaravelData\Data;

/**
 * "Generate a Dockerfile" in a service's Settings → Build (docs/20 §3, §7):
 * whether it is offered, why not, and the newest generation to resume.
 */
final class DockerfileGenerationPanelData extends Data
{
    public function __construct(
        public bool $available,
        /** not_configured | team_off | unsupported, when not available. */
        public ?string $reason,
        public string $model,
        public string $providerLabel,
        public bool $canStart,
        /** An instance owner (or team owner for team_off) can turn it on. */
        public bool $canEnable,
        /** The plan preview failed with plan_error: offer it first. */
        public bool $planFailed,
        public ?DockerfileGenerationData $latest,
    ) {}
}
