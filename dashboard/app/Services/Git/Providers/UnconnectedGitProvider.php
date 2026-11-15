<?php

declare(strict_types=1);

namespace App\Services\Git\Providers;

use App\Contracts\Git\GitProvider;
use App\Contracts\Git\SourceRepository;
use App\Data\Git\CommitData;
use App\Data\Git\DeploymentStatusReport;
use App\Data\Git\GitCredentials;
use App\Data\Git\PolledHead;
use App\Data\Git\RepositoryRef;
use App\Data\Git\RepositoryTree;
use App\Data\Services\GitSource;
use App\Enums\Git\GitProviderKind;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\Git\GitConnectionUnusable;
use App\Exceptions\Git\RepositoryFileUnavailable;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Previews\Preview;
use App\Models\Team;
use App\Services\Git\GitProviders;
use App\Services\Git\PublicGitRemote;

/**
 * A plain smart-HTTP remote without a connection or credentials
 * (`GitSource` kind `git` without `installationId`, docs/19 §2.1): the dev
 * `git-fixtures` server. {@see GitSource::assertUsable()} refuses it unless
 * `launchroom.git.plain_sources` is on, so every method that reaches the
 * remote checks that first. Pushes are not observed (no webhooks, no
 * polling); heads come from the ref advertisement.
 *
 * Resolved by {@see GitProviders::forSource()}, never by kind.
 */
final readonly class UnconnectedGitProvider implements GitProvider
{
    public function __construct(
        private PublicGitRemote $remote,
    ) {}

    public function kind(): GitProviderKind
    {
        return GitProviderKind::Git;
    }

    public function searchRepositories(Team $team, string $search): array
    {
        return [];
    }

    public function branches(SourceRepository $repository): array
    {
        return [];
    }

    public function defaultBranch(RepositoryRef $repository): ?string
    {
        return null;
    }

    public function pollHead(RepositoryRef $repository, string $branch, ?string $etag): PolledHead
    {
        return new PolledHead($this->head($repository, $branch));
    }

    public function head(RepositoryRef $repository, string $branch): CommitData
    {
        try {
            $url = $this->usableUrl($repository);
            // Dev only: the fixture server lives on the dev network.
            $refs = $this->remote->refs($url, 'refs/heads/'.$branch, privateNetwork: true)['refs'];
        } catch (DomainRuleViolation|GitConnectionUnusable $e) {
            throw new GitHeadUnavailable($e->getMessage(), $e->getCode(), $e);
        }

        $sha = $refs['refs/heads/'.$branch] ?? null;

        if ($sha === null) {
            throw new GitHeadUnavailable("Branch {$branch} does not exist in {$repository->fullName}.");
        }

        return new CommitData($sha, '', null, null, null);
    }

    public function canReadFiles(): bool
    {
        return false;
    }

    public function tree(RepositoryRef $repository, string $ref): RepositoryTree
    {
        throw new RepositoryFileUnavailable('Plain git remotes have no file API, so launchroom reads the files when it builds.');
    }

    public function fileAtRef(RepositoryRef $repository, string $path, string $ref): ?string
    {
        throw new RepositoryFileUnavailable('Plain git remotes have no file API, so launchroom reads the files when it builds.');
    }

    public function cloneUrl(RepositoryRef $repository): string
    {
        return $this->usableUrl($repository);
    }

    public function cloneCredentials(RepositoryRef $repository): GitCredentials
    {
        return new GitCredentials($this->usableUrl($repository));
    }

    public function changedFiles(RepositoryRef $repository, int $number, string $baseRef, string $headSha): ?array
    {
        return null;
    }

    public function pullRequestOpen(Preview $preview): ?bool
    {
        return null;
    }

    public function compareUrl(RepositoryRef $repository, string $from, string $to): ?string
    {
        return null;
    }

    public function repositoryUrl(RepositoryRef $repository, ?string $branch = null): ?string
    {
        return null;
    }

    public function fileUrl(RepositoryRef $repository, string $path, string $ref, ?int $line = null): ?string
    {
        return null;
    }

    public function reportDeployment(DeploymentStatusReport $report): void {}

    public function reportPreview(Preview $preview): void {}

    /**
     * @throws DomainRuleViolation
     */
    private function usableUrl(RepositoryRef $repository): string
    {
        $source = new GitSource(0, $repository->fullName, provider: GitProviderKind::Git, cloneUrl: $repository->cloneUrl);
        $source->assertUsable();

        return (string) $source->cloneUrl;
    }
}
