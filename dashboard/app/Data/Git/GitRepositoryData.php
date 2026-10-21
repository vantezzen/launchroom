<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Enums\Git\GitProviderKind;
use App\Models\Git\GitHubRepository;
use App\Models\Git\GitRepository;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A repository in the repo picker and the import list, from any provider
 * (docs/19 §2). `id` is the picker id (`ghr_…` or `gir_…`), `installationId`
 * the public id of its installation or connection, `serverKind` the logo
 * (`github`, `gitlab`, `gitea`, `forgejo`, `git`) and `connectionName` the
 * account or host it came through.
 */
final class GitRepositoryData extends Data
{
    public function __construct(
        public string $id,
        public string $installationId,
        /** The provider's numeric repository id (0 for plain git). */
        public int $githubRepoId,
        public string $fullName,
        public ?string $defaultBranch,
        public bool $private,
        public bool $archived,
        /** Last push (newest first in the picker; the import flow shows it). */
        public ?CarbonInterface $pushedAt = null,
        public GitProviderKind $provider = GitProviderKind::GitHub,
        public string $serverKind = 'github',
        public ?string $connectionName = null,
    ) {}

    public static function fromRepository(GitHubRepository $repository): self
    {
        return new self(
            $repository->public_id,
            $repository->installation->public_id,
            $repository->github_repo_id,
            $repository->full_name,
            $repository->default_branch,
            $repository->private,
            $repository->archived,
            $repository->pushed_at,
            GitProviderKind::GitHub,
            'github',
            $repository->installation->account_login,
        );
    }

    public static function fromSource(GitRepository $repository): self
    {
        $connection = $repository->gitConnection;

        return new self(
            $repository->public_id,
            $connection->public_id,
            $repository->external_id,
            $repository->full_name,
            $repository->default_branch,
            $repository->private,
            $repository->archived,
            $repository->pushed_at,
            $connection->provider,
            $connection->server_kind->value,
            $connection->name,
        );
    }
}
