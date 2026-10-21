<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Enums\Git\DeploymentReportState;
use App\Services\GitHub\GitHubDeploymentReporter;
use Spatie\LaravelData\Data;

/**
 * What {@see GitHubDeploymentReporter} reports for one
 * launchroom deployment of one service. Built by the core domain from its
 * deployment (on `DeploymentStatusChanged`).
 *
 * - `provider`: the service's `GitSource::provider` (docs/19);
 *   `installationId`: its connection row (`github_installations.id` or
 *   `git_connections.id`); `repositoryId`: the provider's numeric repository id.
 * - `deploymentKey`: the launchroom deployment's public id; reports with the
 *   same key update the same GitHub deployment and check run.
 * - `deploymentUrl`: the deployment page in launchroom (logs);
 *   `environmentUrl`: where the deployed service is reachable, if public.
 * - `pullRequestNumber`: set for preview environments; adds/updates the row
 *   in the single PR comment.
 * - `deploymentApi`: false for previews, whose GitHub Deployment is one per
 *   preview environment (App\Services\Previews\PreviewGitHub); check runs
 *   and the comment row stay per service.
 */
final class DeploymentStatusReport extends Data
{
    public function __construct(
        public int $teamId,
        public int $installationId,
        public int $repositoryId,
        public string $deploymentKey,
        public string $serviceName,
        public string $environmentName,
        public bool $productionEnvironment,
        public bool $transientEnvironment,
        public string $commitSha,
        public DeploymentReportState $state,
        public string $deploymentUrl,
        public ?string $environmentUrl = null,
        public ?string $description = null,
        public ?int $pullRequestNumber = null,
        public bool $deploymentApi = true,
        public string $provider = 'github',
    ) {}
}
