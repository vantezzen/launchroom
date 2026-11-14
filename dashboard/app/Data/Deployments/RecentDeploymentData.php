<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * A row of "Recent deployments" on the projects page (docs/08 §13): the
 * deployment and the name of its project.
 */
final class RecentDeploymentData extends Data
{
    public function __construct(
        public DeploymentSummaryData $deployment,
        public string $projectName,
    ) {}
}
