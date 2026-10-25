<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

/**
 * One normalised path of one project, for the team overview's top paths.
 */
final class AnalyticsTeamPathData extends Data
{
    public function __construct(
        public string $projectId,
        public string $projectName,
        public string $path,
        public int $requests,
        public int $status4xx,
        public int $status5xx,
    ) {}
}
