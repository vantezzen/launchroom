<?php

declare(strict_types=1);

namespace App\Services\Git\Providers;

use App\Actions\Git\GitCloneCredentials;
use App\Actions\Git\ListRepositoryBranches;
use App\Contracts\Git\GitProvider;
use App\Contracts\Git\OpensPullRequests;
use App\Contracts\Git\SourceRepository;
use App\Data\Git\CommitData;
use App\Data\Git\DeploymentStatusReport;
use App\Data\Git\FilePullRequest;
use App\Data\Git\RepositoryRef;
use App\Data\Git\RepositoryTree;
use App\Data\Services\GitSource;
use App\Enums\Git\GitProviderKind;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Models\Previews\Preview;
use App\Models\Team;
use App\Services\Git\Exceptions\RepositoryFileUnavailable;
use App\Services\Git\GitCredentials;
use App\Services\GitHub\Exceptions\GitHubRequestFailed;
use App\Services\GitHub\GitHubClientFactory;
use App\Services\GitHub\GitHubDeploymentReporter;
use App\Services\GitHub\JsonObject;
use App\Services\GitHub\TokenScope;
use App\Services\Previews\PreviewGitHub;
use App\Support\Git\GitHubAvatar;
use Illuminate\Contracts\Database\Query\Builder;
use Throwable;

/**
 * GitHub behind the provider contract (docs/19 §2). Behaviour is the GitHub
 * App integration as it was before D46: installation tokens per repository
 * and scope, check runs and the Deployments API, the PR comment table.
 */
final readonly class GitHubProvider implements GitProvider, OpensPullRequests
{
    public function __construct(
        private GitHubClientFactory $clients,
        private ListRepositoryBranches $listBranches,
        private GitCloneCredentials $cloneCredentials,
        private GitHubDeploymentReporter $reporter,
        private PreviewGitHub $previews,
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

        return $this->listBranches->handle($repository);
    }

    public function defaultBranch(RepositoryRef $repository): ?string
    {
        return $this->repository($repository)?->default_branch;
    }

    public function head(RepositoryRef $repository, string $branch): CommitData
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
                accept: [404, 422],
            );
        } catch (Throwable) {
            throw new GitHeadUnavailable("GitHub did not answer for {$repository->fullName}; try again in a moment.");
        }

        if ($response->status() !== 200) {
            throw new GitHeadUnavailable("Branch {$branch} does not exist in {$repository->fullName}.");
        }

        $json = JsonObject::decode($response->body());
        $commit = $json->objectOrNull('commit');
        $author = $commit?->objectOrNull('author');
        $login = $json->objectOrNull('author')?->stringOrNull('login');

        return new CommitData(
            sha: $json->string('sha'),
            message: $commit?->stringOrNull('message') ?? '',
            authorName: $author?->stringOrNull('name'),
            authorEmail: $author?->stringOrNull('email'),
            authorLogin: $login,
            authorAvatarUrl: GitHubAvatar::url($json->objectOrNull('author')?->stringOrNull('avatar_url'), $login),
        );
    }

    /**
     * @throws GitHubRequestFailed
     */
    public function tree(RepositoryRef $repository, string $ref): RepositoryTree
    {
        [$row, $installation] = $this->usable($repository);

        $tree = JsonObject::decode($this->clients->for($installation->app)->asInstallation(
            $installation->installation_id,
            TokenScope::read($row->github_repo_id),
            'GET',
            "/repos/{$row->full_name}/git/trees/".rawurlencode($ref),
            query: ['recursive' => 1],
        )->body());

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
        [$row, $installation] = $this->usable($repository);

        try {
            $response = $this->clients->for($installation->app)->asInstallation(
                $installation->installation_id,
                TokenScope::read($row->github_repo_id),
                'GET',
                "/repos/{$row->full_name}/contents/".implode('/', array_map(rawurlencode(...), explode('/', $path))),
                query: ['ref' => $ref],
                headers: ['Accept' => 'application/vnd.github.raw+json'],
                accept: [404],
            );
        } catch (GitHubRequestFailed $e) {
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

        return rtrim($row->installation->app->web_url, '/').'/'.$row->full_name.'.git';
    }

    public function cloneCredentials(RepositoryRef $repository): GitCredentials
    {
        $team = Team::query()->whereKey($repository->teamId)->first();

        if (! $team instanceof Team) {
            throw DomainRuleViolation::on('source', 'The repository’s team no longer exists.');
        }

        $credentials = $this->cloneCredentials->handle($team, new GitSource($repository->repositoryId, $repository->fullName, $repository->connectionId));

        return new GitCredentials($credentials->cloneUrl, token: $credentials->token->reveal());
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

    public function compareUrl(RepositoryRef $repository, string $from, string $to): string
    {
        return "https://github.com/{$repository->fullName}/compare/{$from}...{$to}";
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
     * A branch from the default branch, one commit adding the file, a pull
     * request (docs/20 §5), with a token for exactly that repository.
     *
     * @throws GitHubRequestFailed
     */
    public function openFilePullRequest(RepositoryRef $repository, FilePullRequest $request): string
    {
        [$row, $installation] = $this->usable($repository);
        $client = $this->clients->for($installation->app);
        $scope = TokenScope::propose($row->github_repo_id);
        $repo = "/repos/{$row->full_name}";
        $base = $row->default_branch ?? $this->defaultBranch($repository) ?? 'main';
        $path = implode('/', array_map(rawurlencode(...), explode('/', $request->path)));

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

    /**
     * @return array{GitHubRepository, GitHubInstallation}
     *
     * @throws GitHubRequestFailed
     */
    private function usable(RepositoryRef $repository): array
    {
        $row = $this->repository($repository);
        $installation = $row?->installation;

        if (! $row instanceof GitHubRepository || ! $installation instanceof GitHubInstallation || ! $installation->isActive()) {
            throw new GitHubRequestFailed("The GitHub App can't access {$repository->fullName}.", 404);
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
