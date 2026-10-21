<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Enums\Git\RepositorySelection;
use App\Models\Git\GitHubInstallation;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

final class GitHubInstallationData extends Data
{
    public function __construct(
        public string $id,
        public string $accountLogin,
        public string $accountType,
        public RepositorySelection $repositorySelection,
        public int $repositoryCount,
        public bool $suspended,
        public ?CarbonInterface $syncedAt,
        public string $settingsUrl,
    ) {}

    /**
     * Expects `repositories_count` (withCount) and the app loaded.
     */
    public static function fromInstallation(GitHubInstallation $installation): self
    {
        $count = $installation->getAttribute('repositories_count');
        $web = rtrim($installation->app->web_url, '/');

        return new self(
            $installation->public_id,
            $installation->account_login,
            $installation->account_type,
            $installation->repository_selection,
            is_int($count) ? $count : 0,
            $installation->suspended_at !== null,
            $installation->repositories_synced_at,
            $installation->account_type === 'Organization'
                ? "{$web}/organizations/{$installation->account_login}/settings/installations/{$installation->installation_id}"
                : "{$web}/settings/installations/{$installation->installation_id}",
        );
    }
}
