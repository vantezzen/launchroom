<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Models\Git\GitRepository;
use App\Services\Crypto\SealedColumns;
use App\Services\Git\GitProviders;

/**
 * Registers launchroom's webhook on a GitLab/Gitea repository once a service
 * uses it (docs/19 §5). Failures are kept on the repository row; polling
 * covers pushes meanwhile.
 */
final readonly class EnsureRepositoryWebhook
{
    public function __construct(
        private GitProviders $providers,
        private SealedColumns $sealed,
    ) {}

    public function handle(GitRepository $repository): void
    {
        $connection = $repository->gitConnection;
        if (! $connection->isActive()) {
            return;
        }

        $this->providers->connection($connection->provider)->ensureWebhook($repository, (string) $this->sealed->reveal($connection, 'webhook_secret'));
    }
}
