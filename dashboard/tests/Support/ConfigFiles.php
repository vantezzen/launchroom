<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Git\GitProvider;
use App\Contracts\Git\SourceRepository;
use App\Data\Git\CommitData;
use App\Data\Git\DeploymentStatusReport;
use App\Data\Git\RepositoryRef;
use App\Data\Git\RepositoryTree;
use App\Enums\Git\GitProviderKind;
use App\Models\Previews\Preview;
use App\Models\Team;
use App\Services\Git\GitCredentials;
use App\Services\Git\Providers\PlainGitProvider;

/**
 * Plain git servers have no file API; tests give them one: launchroom.json
 * contents per commit SHA (docs/18). Everything else is the real provider.
 */
final readonly class ConfigFiles implements GitProvider
{
    /**
     * @param  array<string, string>  $files  commit SHA → launchroom.json
     */
    public static function serve(array $files): void
    {
        $real = app()->make(PlainGitProvider::class);
        app()->instance(PlainGitProvider::class, new self($real, $files));
    }

    /**
     * @param  array<string, string>  $files
     */
    private function __construct(
        private GitProvider $real,
        private array $files,
    ) {}

    public function fileAtRef(RepositoryRef $repository, string $path, string $ref): ?string
    {
        return $path === 'launchroom.json' ? ($this->files[$ref] ?? null) : null;
    }

    public function kind(): GitProviderKind
    {
        return $this->real->kind();
    }

    public function searchRepositories(Team $team, string $search): array
    {
        return $this->real->searchRepositories($team, $search);
    }

    public function branches(SourceRepository $repository): array
    {
        return $this->real->branches($repository);
    }

    public function defaultBranch(RepositoryRef $repository): ?string
    {
        return $this->real->defaultBranch($repository);
    }

    public function head(RepositoryRef $repository, string $branch): CommitData
    {
        return $this->real->head($repository, $branch);
    }

    public function tree(RepositoryRef $repository, string $ref): RepositoryTree
    {
        return $this->real->tree($repository, $ref);
    }

    public function cloneUrl(RepositoryRef $repository): string
    {
        return $this->real->cloneUrl($repository);
    }

    public function cloneCredentials(RepositoryRef $repository): GitCredentials
    {
        return $this->real->cloneCredentials($repository);
    }

    public function changedFiles(RepositoryRef $repository, int $number, string $baseRef, string $headSha): ?array
    {
        return $this->real->changedFiles($repository, $number, $baseRef, $headSha);
    }

    public function pullRequestOpen(Preview $preview): ?bool
    {
        return $this->real->pullRequestOpen($preview);
    }

    public function compareUrl(RepositoryRef $repository, string $from, string $to): ?string
    {
        return $this->real->compareUrl($repository, $from, $to);
    }

    public function reportDeployment(DeploymentStatusReport $report): void
    {
        $this->real->reportDeployment($report);
    }

    public function reportPreview(Preview $preview): void
    {
        $this->real->reportPreview($preview);
    }
}
