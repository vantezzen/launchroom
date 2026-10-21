<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Services\GitHub\Dto\InstallationInfo;
use App\Services\GitHub\GitHubClientFactory;

/**
 * The team's installation a verified webhook refers to. Unknown installations
 * (e.g. the `installation.created` delivery was lost) are confirmed through
 * the App JWT API before they are stored.
 */
final readonly class ResolveInstallation
{
    public function __construct(
        private GitHubClientFactory $clients,
        private SyncGitHubInstallation $sync,
    ) {}

    public function handle(GitHubApp $app, int $installationId): ?GitHubInstallation
    {
        $installation = $app->installations()->where('installation_id', $installationId)->first();

        if ($installation instanceof GitHubInstallation) {
            return $installation->isActive() ? $installation->setRelation('app', $app) : null;
        }

        $info = $this->clients->for($app)->installation($installationId);

        return $info instanceof InstallationInfo ? $this->sync->handle($app, $info) : null;
    }
}
