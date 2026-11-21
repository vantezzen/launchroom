<?php

declare(strict_types=1);

namespace App\Services\Git\Providers\GitHub;

use App\Contracts\Git\GitProvider;
use App\Contracts\Git\OpensPullRequests;
use App\Contracts\Git\ReadsCommitChecks;
use App\Contracts\Git\SourceRepository;
use App\Data\Git\CommitData;
use App\Data\Git\DeploymentStatusReport;
use App\Data\Git\FilePullRequest;
use App\Data\Git\GitBranchData;
use App\Data\Git\GitCredentials;
use App\Data\Git\PolledHead;
use App\Data\Git\RepositoryRef;
use App\Data\Git\RepositoryTree;
use App\Enums\Git\GitProviderKind;
use App\Exceptions\CommitChecksUnavailable;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\Git\RepositoryFileUnavailable;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Models\Previews\Preview;
use App\Models\Team;
use App\Services\Git\Providers\GitHub\Dto\BranchInfo;
use App\Services\Git\Providers\GitHub\Exceptions\GitHubRequestFailed;
use App\Support\Git\GitHubAvatar;
use App\Support\Git\GitRef;
use App\Support\Git\GitWebPath;
use App\Support\Json\JsonObject;
use Illuminate\Contracts\Database\Query\Builder;
use Throwable;

/**
 * GitHub behind the provider contract (docs/19 §2): GitHub App installation
 * tokens per repository and scope, check runs and the Deployments API, the
 * PR comment. Web links use the App's `web_url`, so GitHub Enterprise
 * Server repositories link to their own host.
 */
final readonly class GitHubProvider implements GitProvider, OpensPullRequests, ReadsCommitChecks
{
    private const int MAX_BRANCH_PAGES = 3;

    public function __construct(
        private GitHubClientFactory $clients,
        private GitHubDeploymentReporter $reporter,
        private GitHubPreviews $previews,
        private GitHubCommitChecks $checks,
    ) {}

    public function kind(): GitProviderKind
    {
        return GitProviderKind::GitHub;
    }

    public function searchRepositories(Team $team, string $search): array
    {
        $query = GitHubRepository::query()
            ->with('installation')
            ->where('team_id', $team->id)
            ->whereRelation('installation', 'removed_at')
            ->whereRelation('installation', 'suspended_at');

        if ($search !== '') {
            $query->whereLike('full_name', '%'.addcslashes($search, '%_\\').'%');
        }

        return array_values($query->latest('pushed_at')->orderBy('full_name')->limit(50)->get()->all());
    }

    public function branches(SourceRepository $repository): array
    {
        if (! $repository instanceof GitHubRepository) {
            return [];
        }

        $installation = $repository->installation;
        $client = $this->clients->for($installation->app);
        $branches = [];

        for ($page = 1; $page <= self::MAX_BRANCH_PAGES; $page++) {
            $items = JsonObject::decodeList($client->asInstallation(
                $installation->installation_id,
                TokenScope::read($repository->github_repo_id),
                'GET',
                "/repos/{$repository->full_name}/branches",
                query: ['per_page' => 100, 'page' => $page],
            )->body());

            foreach ($items as $item) {
                $branch = BranchInfo::fromJson($item);
                $branches[] = new GitBranchData($branch->name, $branch->sha, $branch->protected, $branch->name === $repository->default_branch);
            }

            if (count($items) < 100) {
                break;
            }
        }

        return $branches;
    }

    public function defaultBranch(RepositoryRef $repository): ?string
    {
        return $this->repository($repository)?->default_branch;
    }

    public function head(RepositoryRef $repository, string $branch): CommitData
    {
        return $this->pollHead($repository, $branch, null)->commit
            ?? throw new GitHeadUnavailable("GitHub did not answer for {$repository->fullName}. Try again in a moment.");
    }

    public function pollHead(RepositoryRef $repository, string $branch, ?string $etag): PolledHead
    {
        $row = $this->repository($repository);
        $installation = $row?->installation;

        if (! $row instanceof GitHubRepository || ! $installation instanceof GitHubInstallation || ! $installation->isActive()) {
            throw new GitHeadUnavailable("The GitHub App can't access {$repository->fullName}. Check the installation in Settings → Git.");
        }

        try {
            $response = $this->clients->for($installation->app)->asInstallation(
                $installation->installation_id,
                TokenScope::read($row->github_repo_id),
                'GET',
                "/repos/{$row->full_name}/commits/".rawurlencode($branch),
                headers: $etag !== null ? ['If-None-Match' => $etag] : [],
                accept: [304, 404, 422],
            );
        } catch (Throwable) {
            throw new GitHeadUnavailable("GitHub did not answer for {$repository->fullName}. Try again in a moment.");
        }

        if ($response->status() === 304 && $etag !== null) {
            return new PolledHead(null, $etag);
        }

        if ($response->status() !== 200) {
            throw new GitHeadUnavailable("Branch {$branch} does not exist in {$repository->fullName}.");
        }

        $json = JsonObject::decode($response->body());
        $commit = $json->objectOrNull('commit');
        $author = $commit?->objectOrNull('author');
        $account = $json->objectOrNull('author');
        $login = $account?->stringOrNull('login');
        $sha = $json->string('sha');

        if (! GitRef::isSha($sha)) {
            throw new GitHeadUnavailable("Branch {$branch} of {$repository->fullName} has no commit launchroom can build.");
        }

        $newEtag = $response->header('ETag');

        return new PolledHead(new CommitData(
            sha: $sha,
            message: $commit?->stringOrNull('message') ?? '',
            authorName: $author?->stringOrNull('name'),
            authorEmail: $author?->stringOrNull('email'),
            authorLogin: $login,
            authorAvatarUrl: GitHubAvatar::url($account?->stringOrNull('avatar_url'), $login),
        ), $newEtag === '' ? null : mb_substr($newEtag, 0, 255));
    }

    public function commitChecks(RepositoryRef $repository, string $sha): array
    {
        [$row, $installation] = $this->usable($repository) ?? throw new CommitChecksUnavailable("The GitHub App can't access {$repository->fullName}. Check the installation in Settings → Git.");

        if (! GitRef::isSha($sha)) {
            throw new CommitChecksUnavailable('This deployment has no commit to read checks for.');
        }

        return $this->checks->read($row, $installation, $sha);
    }

    public function canReadFiles(): bool
    {
        return true;
    }

    public function tree(RepositoryRef $repository, string $ref): RepositoryTree
    {
        [$row, $installation] = $this->usable($repository) ?? throw new RepositoryFileUnavailable("The GitHub App can't access {$repository->fullName}.");

        try {
            $tree = JsonObject::decode($this->clients->for($installation->app)->asInstallation(
                $installation->installation_id,
                TokenScope::read($row->github_repo_id),
                'GET',
                "/repos/{$row->full_name}/git/trees/".rawurlencode($ref),
                query: ['recursive' => 1],
            )->body());
        } catch (Throwable $e) {
            throw new RepositoryFileUnavailable("GitHub didn’t list the files of {$repository->fullName}: {$e->getMessage()}", $e->getCode(), previous: $e);
        }

        $paths = [];

        foreach ($tree->objects('tree') as $entry) {
            if ($entry->stringOrNull('type') === 'blob' && ($path = $entry->stringOrNull('path')) !== null) {
                $paths[] = $path;
            }
        }

        return new RepositoryTree($paths, ! $tree->bool('truncated'));
    }

    public function fileAtRef(RepositoryRef $repository, string $path, string $ref): ?string
    {
        [$row, $installation] = $this->usable($repository) ?? throw new RepositoryFileUnavailable("The GitHub App can't access {$repository->fullName}.");

        try {
            $response = $this->clients->for($installation->app)->asInstallation(
                $installation->installation_id,
                TokenScope::read($row->github_repo_id),
                'GET',
                "/repos/{$row->full_name}/contents/".GitWebPath::path($path),
                query: ['ref' => $ref],
                headers: ['Accept' => 'application/vnd.github.raw+json'],
                accept: [404],
            );
        } catch (Throwable $e) {
            throw new RepositoryFileUnavailable("GitHub didn’t return {$path}: {$e->getMessage()}", $e->getCode(), previous: $e);
        }

        return $response->successful() ? $response->body() : null;
    }

    public function cloneUrl(RepositoryRef $repository): string
    {
        $row = $this->repository($repository);

        if (! $row instanceof GitHubRepository) {
            throw DomainRuleViolation::on('source', "The GitHub App can't access {$repository->fullName}. Check the installation in Settings → Git.");
        }

        return $this->web($row->installation->app, $row->full_name).'.git';
    }

    /**
     * A short-lived installation token that can only read this one
     * repository (contents + metadata), for one build (docs/09 R19), minted
     * fresh and never cached, so no later build reuses it. The installation
     * must belong to the ref's team and be active (docs/09 R18).
     */
    public function cloneCredentials(RepositoryRef $repository): GitCredentials
    {
        $installation = $repository->connectionId === null ? null : GitHubInstallation::query()
            ->with('app')
            ->where('team_id', $repository->teamId)
            ->whereKey($repository->connectionId)
            ->first();

        if (! $installation instanceof GitHubInstallation || ! $installation->isActive()) {
            throw DomainRuleViolation::on('source', 'The GitHub App installation for this repository is missing or suspended. Reinstall it in Settings → Git.');
        }

        $row = $installation->repositories()->where('github_repo_id', $repository->repositoryId)->first();

        if (! $row instanceof GitHubRepository) {
            throw DomainRuleViolation::on('source', 'The GitHub App can’t access this repository anymore. Check the installation’s repository access on GitHub.');
        }

        $token = $this->clients->for($installation->app)->installationToken(
            $installation->installation_id,
            TokenScope::clone($row->github_repo_id),
            fresh: true,
        );

        return new GitCredentials($this->web($installation->app, $row->full_name).'.git', token: $token->reveal());
    }

    public function changedFiles(RepositoryRef $repository, int $number, string $baseRef, string $headSha): ?array
    {
        $installation = GitHubInstallation::query()
            ->with('app')
            ->where('team_id', $repository->teamId)
            ->whereKey($repository->connectionId ?? 0)
            ->first();

        return $installation instanceof GitHubInstallation ? $this->previews->changedFiles($installation, $repository->repositoryId, $baseRef, $headSha) : null;
    }

    public function pullRequestOpen(Preview $preview): ?bool
    {
        return $this->previews->isOpen($preview);
    }

    public function compareUrl(RepositoryRef $repository, string $from, string $to): ?string
    {
        $row = $this->repository($repository);

        return $row instanceof GitHubRepository ? $this->web($row->installation->app, $row->full_name)."/compare/{$from}...{$to}" : null;
    }

    public function repositoryUrl(RepositoryRef $repository, ?string $branch = null): ?string
    {
        $row = $this->repository($repository);

        return $row instanceof GitHubRepository ? $this->web($row->installation->app, $row->full_name).($branch === null ? '' : '/tree/'.GitWebPath::branch($branch)) : null;
    }

    public function fileUrl(RepositoryRef $repository, string $path, string $ref, ?int $line = null): ?string
    {
        $row = $this->repository($repository);

        return $row instanceof GitHubRepository
            ? $this->web($row->installation->app, $row->full_name).'/blob/'.GitWebPath::branch($ref).'/'.GitWebPath::path($path).($line === null ? '' : "#L{$line}")
            : null;
    }

    public function reportDeployment(DeploymentStatusReport $report): void
    {
        $this->reporter->report($report);
    }

    public function reportPreview(Preview $preview): void
    {
        $this->previews->report($preview);
    }

    /**
     * @throws GitHubRequestFailed
     */
    public function openFilePullRequest(RepositoryRef $repository, FilePullRequest $request): string
    {
        [$row, $installation] = $this->usable($repository) ?? throw new GitHubRequestFailed("The GitHub App can't access {$repository->fullName}.", 404);
        $client = $this->clients->for($installation->app);
        $scope = TokenScope::propose($row->github_repo_id);
        $repo = "/repos/{$row->full_name}";
        $base = $row->default_branch ?? $this->defaultBranch($repository) ?? 'main';
        $path = GitWebPath::path($request->path);

        try {
            $head = JsonObject::decode($client->asInstallation($installation->installation_id, $scope, 'GET', "{$repo}/git/ref/heads/".rawurlencode($base))->body());
            $sha = $head->objectOrNull('object')?->stringOrNull('sha');

            if ($sha === null) {
                throw new GitHubRequestFailed("GitHub didn't return the head of {$base}.", 502);
            }

            $existing = $client->asInstallation($installation->installation_id, $scope, 'GET', "{$repo}/contents/{$path}", query: ['ref' => $base], accept: [404]);

            if ($existing->successful()) {
                throw new GitHubRequestFailed("{$request->path} already exists on {$base}.", 409);
            }

            $client->asInstallation($installation->installation_id, $scope, 'POST', "{$repo}/git/refs", ['ref' => 'refs/heads/'.$request->branch, 'sha' => $sha]);
            $client->asInstallation($installation->installation_id, $scope, 'PUT', "{$repo}/contents/{$path}", [
                'message' => $request->commitMessage,
                'content' => base64_encode($request->content),
                'branch' => $request->branch,
            ]);
            $pull = JsonObject::decode($client->asInstallation($installation->installation_id, $scope, 'POST', "{$repo}/pulls", [
                'title' => $request->title,
                'head' => $request->branch,
                'base' => $base,
                'body' => $request->body,
            ])->body());
        } catch (GitHubRequestFailed $e) {
            throw new GitHubRequestFailed(in_array($e->status, [403, 422], true) && ! str_contains($e->getMessage(), 'already exists')
                ? 'GitHub refused: the launchroom GitHub App needs "Contents: Read and write" and "Pull requests: Read and write". Change them in the App’s settings on GitHub, accept the new permissions for the installation, then try again.'
                : $e->getMessage(), $e->status);
        }

        return $pull->string('html_url');
    }

    private function web(GitHubApp $app, string $fullName): string
    {
        return rtrim($app->web_url, '/').'/'.$fullName;
    }

    /**
     * @return array{GitHubRepository, GitHubInstallation}|null
     */
    private function usable(RepositoryRef $repository): ?array
    {
        $row = $this->repository($repository);
        $installation = $row?->installation;

        if (! $row instanceof GitHubRepository || ! $installation instanceof GitHubInstallation || ! $installation->isActive()) {
            return null;
        }

        return [$row, $installation];
    }

    private function repository(RepositoryRef $repository): ?GitHubRepository
    {
        if ($repository->connectionId === null) {
            return null;
        }

        return GitHubRepository::query()
            ->with('installation.app')
            ->where('github_repo_id', $repository->repositoryId)
            ->whereHas('installation', static fn (Builder $query) => $query->where('team_id', $repository->teamId)->whereKey($repository->connectionId))
            ->first();
    }
}
