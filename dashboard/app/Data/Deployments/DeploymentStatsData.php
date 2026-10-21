<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * The deployments header strip ("12 deploys this week · 1 failed · median
 * 1m 20s") and per-service medians for the duration bars (direction.md §8.3).
 * Medians are over successful deployments (went live) of the last 30 days;
 * week counts cover the last 7 days and skip deployments that never ran
 * (cancelled, superseded before starting). Null medians: no data yet.
 */
final class DeploymentStatsData extends Data
{
    /**
     * @param  list<ServiceDeploymentStatsData>  $services
     */
    public function __construct(
        public int $weekTotal,
        public int $weekFailed,
        public ?int $medianDurationSeconds,
        public array $services,
    ) {}
}
