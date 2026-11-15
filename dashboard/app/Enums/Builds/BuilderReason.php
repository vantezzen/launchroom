<?php

declare(strict_types=1);

namespace App\Enums\Builds;

/**
 * Why the plan chose its builder (BuildSummary.builderReason, docs/04 §3).
 */
enum BuilderReason: string
{
    case Explicit = 'explicit';
    case DockerfileDetected = 'dockerfile_detected';
    case Preset = 'preset';
    case Railpack = 'railpack';

    /** Completes "Built …" on the plan summary. */
    public function label(): string
    {
        return match ($this) {
            self::Explicit => 'as configured',
            self::DockerfileDetected => 'from your Dockerfile',
            self::Preset => 'with a launchroom preset',
            self::Railpack => 'with Railpack',
        };
    }
}
