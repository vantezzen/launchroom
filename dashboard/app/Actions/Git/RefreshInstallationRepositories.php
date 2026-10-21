<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Models\Git\GitHubInstallation;
use App\Services\GitHub\GitHubClientFactory;

/**
 * Re-reads every repository an installation can access (on installation
 * events and on demand).
 */
final readonly class RefreshInstallationRepositories
{
    public function __construct(
        private GitHubClientFactory $clients,
        private SyncInstallationRepositories $sync,
    ) {}

    public function handle(GitHubInstallation $installation): void
    {
        if (! $installation->isActive()) {
            return;
        }

        $repositories = $this->clients->for($installation->app)->installationRepositories($installation->installation_id);

        $this->sync->handle($installation, $repositories, complete: true);
    }
}
