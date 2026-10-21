<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Services\GitSource;
use App\Exceptions\DomainRuleViolation;
use App\Models\Git\GitHubInstallation;
use App\Models\Team;
use App\Services\GitHub\CloneCredentials;
use App\Services\GitHub\GitHubClientFactory;
use App\Services\GitHub\TokenScope;

/**
 * A short-lived installation token that can only read one repository
 * (contents + metadata), for one build (docs/09 R19), minted fresh and never
 * cached, so no later build reuses it. It is sealed to the build daemon and
 * never logged, put in a URL or stored.
 *
 * `GitSource::installationId` is `github_installations.id`; the installation
 * must belong to `$team` and be active, and the repository must be one it can
 * access (numeric id, docs/09 R18).
 */
final readonly class GitCloneCredentials
{
    public function __construct(
        private GitHubClientFactory $clients,
    ) {}

    public function handle(Team $team, GitSource $source): CloneCredentials
    {
        $installation = $source->installationId === null ? null : GitHubInstallation::query()
            ->with('app')
            ->where('team_id', $team->id)
            ->where('id', $source->installationId)
            ->first();

        if (! $installation instanceof GitHubInstallation || ! $installation->isActive()) {
            throw DomainRuleViolation::on('source', 'The GitHub App installation for this repository is missing or suspended. Reinstall it in Settings → Git.');
        }

        $repository = $installation->repositories()->where('github_repo_id', $source->repositoryId)->first();

        if ($repository === null) {
            throw DomainRuleViolation::on('source', 'The GitHub App can’t access this repository anymore. Check the installation’s repository access on GitHub.');
        }

        $token = $this->clients->for($installation->app)->installationToken(
            $installation->installation_id,
            TokenScope::clone($repository->github_repo_id),
            fresh: true,
        );

        return new CloneCredentials(
            cloneUrl: rtrim($installation->app->web_url, '/').'/'.$repository->full_name.'.git',
            repositoryId: $repository->github_repo_id,
            token: $token,
        );
    }
}
