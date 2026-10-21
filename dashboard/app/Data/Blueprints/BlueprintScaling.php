<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use Spatie\LaravelData\Data;

/**
 * Replicas and limits from the source; null keeps launchroom's defaults
 * (App\Data\Services\ScalingSettings).
 */
final class BlueprintScaling extends Data
{
    public function __construct(
        public ?int $replicas = null,
        public ?float $cpus = null,
        public ?int $memoryMb = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->replicas === null && $this->cpus === null && $this->memoryMb === null;
    }
}
