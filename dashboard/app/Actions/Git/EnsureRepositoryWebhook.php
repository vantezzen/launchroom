<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Models\Git\GitRepository;
use App\Services\Git\GitConnectionSecrets;
use App\Services\Git\GitProviders;
use App\Services\Git\Providers\ApiGitProvider;

/**
 * Registers launchroom's webhook on a GitLab/Gitea repository once a service
 * uses it (docs/19 §5). Failures are kept on the repository row; polling
 * covers pushes meanwhile.
 */
final readonly class EnsureRepositoryWebhook
{
    public function __construct(
        private GitProviders $providers,
        private GitConnectionSecrets $secrets,
    ) {}

    public function handle(GitRepository $repository): void
    {
        $connection = $repository->gitConnection;
        $provider = $this->providers->for($connection->provider);

        if (! $provider instanceof ApiGitProvider || ! $connection->isActive()) {
            return;
        }

        $provider->ensureWebhook($repository, $this->secrets->webhookSecret($connection));
    }
}
