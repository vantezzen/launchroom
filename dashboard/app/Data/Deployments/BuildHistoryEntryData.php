<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\DeploymentStatus;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One finished deployment of a service instance for the build history
 * sparkline (direction.md §8.3). `buildDurationMs` is null when the image was
 * reused (no build ran).
 */
final class BuildHistoryEntryData extends Data
{
    public function __construct(
        public string $id,
        public DeploymentStatus $status,
        public ?int $buildDurationMs,
        public int $durationSeconds,
        public CarbonInterface $finishedAt,
    ) {}
}
