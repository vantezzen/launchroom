<?php

declare(strict_types=1);

namespace App\Services\Git\Providers;

use App\Contracts\Git\GitProvider;
use App\Contracts\Git\OpensPullRequests;
use App\Contracts\Git\SourceRepository;
use App\Data\Git\CommitData;
use App\Data\Git\DeploymentStatusReport;
use App\Data\Git\FilePullRequest;
use App\Data\Git\GitBranchData;
use App\Data\Git\RepositoryRef;
use App\Data\Git\RepositoryTree;
use App\Enums\Git\DeploymentReportState;
use App\Enums\Git\GitConnectionStatus;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Git\GitConnection;
use App\Models\Git\GitPullRequestComment;
use App\Models\Git\GitRepository;
use App\Models\Previews\Preview;
use App\Models\Team;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Git\Exceptions\GitProviderRequestFailed;
use App\Services\Git\Exceptions\RepositoryFileUnavailable;
use App\Services\Git\GitConnectionTokens;
use App\Services\Git\GitCredentials;
use App\Services\Git\GitProviderApi;
use App\Services\GitHub\GitHubDeploymentReporter;
use App\Services\GitHub\JsonObject;
use App\Services\Previews\PreviewGitHub;
use App\Support\Git\GitRef;
use App\Support\Git\WebhookReachability;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared behaviour of the providers behind a `git_connections` row with an
 * HTTP API (GitLab, Gitea/Forgejo; docs/19): repository cache upserts,
 * team-scoped lookups, commit statuses, the single PR/MR comment table,
 * preview statuses and webhook registration. Subclasses supply paths and
 * payload shapes.
 */
abstract class ApiGitProvider implements GitProvider, OpensPullRequests
{
    /** At most this many changed files are used (more: unknown → rebuild everything). */
    protected const int MAX_CHANGED_FILES = 300;

    public function __construct(
        protected readonly GitProviderApi $api,
        protected readonly GitConnectionTokens $tokens,
        protected readonly PreviewGitHub $previews,
    ) {}

    /**
     * Search request: path + query.
     *
     * @return array{string, array<string, string|int>}
     */
    abstract protected function searchRequest(string $search): array;

    /**
     * Repository rows (attributes for `git_repositories`) from a search response.
     *
     * @return list<array{external_id: int, full_name: string, default_branch: string|null, private: bool, archived: bool, pushed_at: CarbonImmutable|null}>
     */
    abstract protected function parseRepositories(Response $response): array;

    abstract protected function repositoryPath(GitRepository $repository): string;

    /**
     * @return list<GitBranchData>
     */
    abstract protected function parseBranches(GitRepository $repository, Response $response): array;

    abstract protected function branchPath(GitRepository $repository, string $branch): string;

    abstract protected function branchesPath(GitRepository $repository): string;

    abstract protected function parseHead(GitConnection $connection, JsonObject $branch): CommitData;

    abstract protected function treeListing(GitConnection $connection, GitRepository $repository, string $ref): RepositoryTree;

    /**
     * @return array{string, array<string, string>}
     */
    abstract protected function fileRequest(GitRepository $repository, string $path, string $ref): array;

    /**
     * @return list<string>|null
     */
    abstract protected function listChangedFiles(GitConnection $connection, GitRepository $repository, int $number, string $baseRef, string $headSha): ?array;

    abstract protected function pullRequestState(GitConnection $connection, GitRepository $repository, int $number): bool;

    /**
     * Commit status request for one context.
     *
     * @return array{string, array<string, mixed>}
     */
    abstract protected function statusRequest(GitRepository $repository, string $sha, string $context, string $state, string $description, ?string $url): array;

    abstract protected function statusState(DeploymentReportState $state): string;

    abstract protected function commentCreatePath(GitRepository $repository, int $number): string;

    abstract protected function commentUpdatePath(GitRepository $repository, int $number, int $commentId): string;

    /** @return 'PUT'|'PATCH' */
    abstract protected function commentUpdateMethod(): string;

    /**
     * Hook creation request.
     *
     * @return array{string, array<string, mixed>}
     */
    abstract protected function hookRequest(GitRepository $repository, string $url, string $secret): array;

    abstract public function webhookUrl(GitConnection $connection): string;

    /**
     * Adds one file on a new branch from $base and opens a pull/merge request
     * (docs/20 §5); returns its web URL.
     *
     * @throws GitProviderRequestFailed
     */
    abstract protected function proposeFile(GitRepository $repository, FilePullRequest $request, string $base): string;

    public function openFilePullRequest(RepositoryRef $repository, FilePullRequest $request): string
    {
        $row = $this->usable($repository);
        $base = $row->default_branch ?? $this->defaultBranch($repository) ?? 'main';

        try {
            return $this->proposeFile($row, $request, $base);
        } catch (GitProviderRequestFailed $e) {
            throw new GitProviderRequestFailed($row->gitConnection->server_kind->label().' did not open the pull request: '.mb_substr($e->getMessage(), 0, 300), $e->status);
        }
    }

    /**
     * A route URL, rebased on `launchroom.git.webhook_base_url` when set
     * (the provider reaches the dashboard under another address, e.g. dev).
     */
    protected static function deliveryUrl(string $routeUrl): string
    {
        $base = config('launchroom.git.webhook_base_url');

        if (! is_string($base) || $base === '') {
            return $routeUrl;
        }

        $path = (string) parse_url($routeUrl, PHP_URL_PATH);

        return rtrim($base, '/').$path;
    }

    /**
     * A fork author's permission on the base repository
     * (admin/maintain/write/read/none, or `unknown` when the lookup failed,
     * which counts as no write access, docs/09 R20).
     */
    abstract public function authorPermission(GitConnection $connection, GitRepository $repository, string $user): string;

    public function searchRepositories(Team $team, string $search): array
    {
        $repositories = [];

        $connections = GitConnection::query()
            ->where('team_id', $team->id)
            ->where('provider', $this->kind()->value)
            ->where('status', GitConnectionStatus::Active->value)
            ->orderBy('id')
            ->get();

        foreach ($connections as $connection) {
            try {
                $repositories = [...$repositories, ...$this->searchConnection($connection, $search)];
            } catch (Throwable $e) {
                Log::warning('Listing repositories of a git connection failed', ['connection' => $connection->public_id, 'error' => $e->getMessage()]);

                // Fall back to what we saw before.
                $repositories = [...$repositories, ...$this->cached($connection, $search)];
            }
        }

        usort($repositories, static fn (GitRepository $a, GitRepository $b): int => ($b->pushed_at?->getTimestamp() ?? 0) <=> ($a->pushed_at?->getTimestamp() ?? 0));

        return array_slice($repositories, 0, 50);
    }

    /**
     * @return list<GitRepository>
     *
     * @throws GitProviderRequestFailed
     * @throws GitConnectionUnusable
     */
    public function searchConnection(GitConnection $connection, string $search): array
    {
        $key = 'launchroom:git:search:'.$connection->id.':'.hash('sha256', $search);
        /** @var list<int>|null $ids */
        $ids = Cache::get($key);

        if (! is_array($ids)) {
            [$path, $query] = $this->searchRequest($search);
            $ids = [];

            foreach ($this->parseRepositories($this->api->send($connection, 'GET', $path, query: $query)) as $attributes) {
                $ids[] = $this->remember($connection, $attributes)->id;
            }

            $connection->forceFill(['repositories_synced_at' => now()])->save();
            Cache::put($key, $ids, 60);
        }

        return array_values(GitRepository::query()
            ->with('gitConnection')
            ->where('git_connection_id', $connection->id)
            ->whereIn('id', $ids)
            ->get()
            ->all());
    }

    /**
     * @return list<GitRepository>
     */
    private function cached(GitConnection $connection, string $search): array
    {
        $query = GitRepository::query()->with('gitConnection')->where('git_connection_id', $connection->id);

        if ($search !== '') {
            $query->whereLike('full_name', '%'.addcslashes($search, '%_\\').'%');
        }

        return array_values($query->latest('pushed_at')->limit(50)->get()->all());
    }

    /**
     * Upserts one repository row of a connection.
     *
     * @param  array{external_id: int, full_name: string, default_branch: string|null, private: bool, archived: bool, pushed_at: CarbonImmutable|null}  $attributes
     */
    public function remember(GitConnection $connection, array $attributes): GitRepository
    {
        $repository = GitRepository::query()->firstOrNew([
            'git_connection_id' => $connection->id,
            'external_id' => $attributes['external_id'],
        ]);

        $repository->fill([
            'team_id' => $connection->team_id,
            'full_name' => mb_substr($attributes['full_name'], 0, 255),
            'default_branch' => $attributes['default_branch'] === null ? null : mb_substr($attributes['default_branch'], 0, 255),
            'private' => $attributes['private'],
            'archived' => $attributes['archived'],
            'pushed_at' => $attributes['pushed_at'],
            'web_url' => $connection->origin().'/'.$attributes['full_name'],
        ]);
        $repository->save();
        $repository->setRelation('gitConnection', $connection);

        return $repository;
    }

    public function branches(SourceRepository $repository): array
    {
        if (! $repository instanceof GitRepository) {
            return [];
        }

        $connection = $repository->gitConnection;
        $branches = [];

        for ($page = 1; $page <= 3; $page++) {
            $response = $this->api->send($connection, 'GET', $this->branchesPath($repository), query: ['per_page' => 100, 'limit' => 100, 'page' => $page]);
            $items = $this->parseBranches($repository, $response);
            $branches = [...$branches, ...$items];

            if (count($items) < 100) {
                break;
            }
        }

        return $branches;
    }

    public function defaultBranch(RepositoryRef $repository): ?string
    {
        return $this->row($repository)?->default_branch;
    }

    public function head(RepositoryRef $repository, string $branch): CommitData
    {
        $row = $this->row($repository);
        $label = $this->kind()->label();

        if (! $row instanceof GitRepository || ! $row->gitConnection->isActive()) {
            throw new GitHeadUnavailable("launchroom can't access {$repository->fullName}. Check its connection in Settings → Git.");
        }

        try {
            $response = $this->api->send($row->gitConnection, 'GET', $this->branchPath($row, $branch), accept: [404]);
        } catch (GitConnectionUnusable $e) {
            throw new GitHeadUnavailable($e->getMessage(), $e->getCode(), previous: $e);
        } catch (Throwable) {
            throw new GitHeadUnavailable("{$label} did not answer for {$repository->fullName}. Try again in a moment.");
        }

        if ($response->status() === 404) {
            throw new GitHeadUnavailable("Branch {$branch} does not exist in {$repository->fullName}.");
        }

        $commit = $this->parseHead($row->gitConnection, JsonObject::decode($response->body()));

        if (! GitRef::isSha($commit->sha)) {
            throw new GitHeadUnavailable("Branch {$branch} of {$repository->fullName} has no commit launchroom can build.");
        }

        return $commit;
    }

    public function tree(RepositoryRef $repository, string $ref): RepositoryTree
    {
        $row = $this->usable($repository);

        try {
            return $this->treeListing($row->gitConnection, $row, $ref);
        } catch (RepositoryFileUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RepositoryFileUnavailable($e->getMessage(), $e->getCode(), previous: $e);
        }
    }

    public function fileAtRef(RepositoryRef $repository, string $path, string $ref): ?string
    {
        $row = $this->usable($repository);
        [$requestPath, $query] = $this->fileRequest($row, $path, $ref);

        try {
            $response = $this->api->send($row->gitConnection, 'GET', $requestPath, query: $query, accept: [404], headers: ['Accept' => '*/*']);
        } catch (Throwable $e) {
            throw new RepositoryFileUnavailable($e->getMessage(), $e->getCode(), previous: $e);
        }

        return $response->successful() ? $response->body() : null;
    }

    public function cloneUrl(RepositoryRef $repository): string
    {
        $row = $this->row($repository);

        if (! $row instanceof GitRepository) {
            throw DomainRuleViolation::on('source', "launchroom can't access {$repository->fullName}. Check its connection in Settings → Git.");
        }

        // Built from the connection's origin, never from a URL the API returned.
        return $row->gitConnection->origin().'/'.$row->full_name.'.git';
    }

    public function cloneCredentials(RepositoryRef $repository): GitCredentials
    {
        $row = $this->row($repository);

        if (! $row instanceof GitRepository) {
            throw DomainRuleViolation::on('source', "launchroom can't access {$repository->fullName} anymore. Check its connection in Settings → Git.");
        }

        try {
            $token = $this->tokens->accessToken($row->gitConnection);
        } catch (GitConnectionUnusable $e) {
            throw DomainRuleViolation::on('source', $e->getMessage());
        }

        return new GitCredentials($this->cloneUrl($repository), token: $token);
    }

    public function changedFiles(RepositoryRef $repository, int $number, string $baseRef, string $headSha): ?array
    {
        $row = $this->row($repository);

        if (! $row instanceof GitRepository || ! GitRef::isSha($headSha)) {
            return null;
        }

        try {
            $files = $this->listChangedFiles($row->gitConnection, $row, $number, $baseRef, $headSha);
        } catch (Throwable $e) {
            Log::warning('Could not list a pull request’s changed files', ['repository' => $row->public_id, 'error' => $e->getMessage()]);

            return null;
        }

        return $files === null || count($files) >= self::MAX_CHANGED_FILES ? null : array_values(array_unique($files));
    }

    public function pullRequestOpen(Preview $preview): ?bool
    {
        $row = $this->previewRepository($preview);

        if (! $row instanceof GitRepository) {
            return null;
        }

        try {
            return $this->pullRequestState($row->gitConnection, $row, $preview->pr_number);
        } catch (Throwable $e) {
            Log::warning('Could not read a pull request’s state', ['preview' => $preview->public_id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function reportDeployment(DeploymentStatusReport $report): void
    {
        try {
            $row = GitRepository::query()
                ->with('gitConnection')
                ->where('team_id', $report->teamId)
                ->where('git_connection_id', $report->installationId)
                ->where('external_id', $report->repositoryId)
                ->first();

            if (! $row instanceof GitRepository || ! $row->gitConnection->isActive() || ! GitRef::isSha($report->commitSha)) {
                return;
            }

            $context = mb_substr("launchroom: {$report->serviceName} ({$report->environmentName})", 0, 255);
            [$path, $body] = $this->statusRequest($row, $report->commitSha, $context, $this->statusState($report->state), self::truncate($report->description ?? $report->state->label(), 140), $report->deploymentUrl);
            $this->api->send($row->gitConnection, 'POST', $path, $body);

            if ($report->pullRequestNumber !== null) {
                $this->comment($row, $report);
            }
        } catch (Throwable $e) {
            Log::warning('Reporting a deployment to a git provider failed', [
                'provider' => $this->kind()->value,
                'deployment' => $report->deploymentKey,
                'repository' => $report->repositoryId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function reportPreview(Preview $preview): void
    {
        $row = $this->previewRepository($preview);

        if (! $row instanceof GitRepository) {
            return;
        }

        try {
            $preview->refresh();

            if (! $preview->status->isOpen() || $preview->environment_id === null) {
                $this->retireComment($row, $preview);

                return;
            }

            [$state, $url, $description] = $this->previews->aggregate($preview);

            if ($state === null) {
                return;
            }

            $reportState = match ($state) {
                'success' => DeploymentReportState::Success,
                'failure' => DeploymentReportState::Failure,
                default => DeploymentReportState::InProgress,
            };

            [$path, $body] = $this->statusRequest($row, $preview->head_sha, 'launchroom: preview', $this->statusState($reportState), self::truncate($description, 140), $url);
            $this->api->send($row->gitConnection, 'POST', $path, $body);
        } catch (Throwable $e) {
            Log::warning('Reporting a preview to a git provider failed', ['preview' => $preview->public_id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Registers launchroom's webhook on a repository once (push and pull/
     * merge request events, the connection's secret). Skipped when the
     * dashboard isn't reachable from a public provider; the error is kept
     * on the row and polling covers pushes.
     */
    public function ensureWebhook(GitRepository $repository, string $secret): void
    {
        if ($repository->hook_id !== null) {
            return;
        }

        $connection = $repository->gitConnection;
        $url = $this->webhookUrl($connection);

        if (! $connection->allow_private_network && ! WebhookReachability::isPublic($url)) {
            $repository->forceFill(['hook_error' => 'The dashboard isn’t reachable from the internet, so launchroom polls the repository for pushes.'])->save();

            return;
        }

        [$path, $body] = $this->hookRequest($repository, $url, $secret);

        try {
            $response = $this->api->send($connection, 'POST', $path, $body);
            $repository->forceFill(['hook_id' => JsonObject::decode($response->body())->int('id'), 'hook_error' => null])->save();
        } catch (Throwable $e) {
            $repository->forceFill(['hook_error' => mb_substr($e->getMessage(), 0, 255)])->save();
        }
    }

    /**
     * Removes the webhook launchroom registered (best effort, on disconnect).
     */
    public function removeWebhook(GitRepository $repository): void
    {
        if ($repository->hook_id === null) {
            return;
        }

        try {
            $this->api->send($repository->gitConnection, 'DELETE', $this->repositoryPath($repository).'/hooks/'.$repository->hook_id, accept: [404]);
        } catch (Throwable) {
            // The connection is going away anyway.
        }
    }

    private function comment(GitRepository $row, DeploymentStatusReport $report): void
    {
        $number = (int) $report->pullRequestNumber;

        Cache::lock("launchroom:git:pr-comment:{$row->id}:{$number}", 30)->block(20, function () use ($row, $report, $number): void {
            $comment = GitPullRequestComment::query()->firstOrCreate(
                ['git_connection_id' => $row->git_connection_id, 'external_id' => $row->external_id, 'pr_number' => $number],
                ['team_id' => $row->team_id],
            );

            $rows = $comment->rows;
            $rows["{$report->serviceName}|{$report->environmentName}"] = [
                'service' => $report->serviceName,
                'environment' => $report->environmentName,
                'state' => $report->state->value,
                'url' => $report->environmentUrl,
                'logUrl' => $report->deploymentUrl,
                'sha' => $report->commitSha,
                'updatedAt' => CarbonImmutable::now()->toIso8601ZuluString(),
            ];
            ksort($rows);
            $comment->rows = $rows;
            $comment->save();

            $this->publishComment($row, $comment, GitHubDeploymentReporter::renderComment($rows));
        });
    }

    private function retireComment(GitRepository $row, Preview $preview): void
    {
        $comment = GitPullRequestComment::query()
            ->where('git_connection_id', $row->git_connection_id)
            ->where('external_id', $row->external_id)
            ->where('pr_number', $preview->pr_number)
            ->first();

        if (! $comment instanceof GitPullRequestComment || $comment->comment_id === null) {
            return;
        }

        $rows = $comment->rows;

        foreach ($rows as $key => $entry) {
            if ($entry['environment'] === $preview->githubEnvironment() || $entry['environment'] === $preview->slug()) {
                $rows[$key] = [...$entry, 'state' => 'cancelled', 'url' => null];
            }
        }

        $comment->rows = $rows;
        $comment->save();

        $this->publishComment($row, $comment, GitHubDeploymentReporter::renderComment($rows)."\n_The preview environment was removed ({$preview->status->label()})._\n");
    }

    private function publishComment(GitRepository $row, GitPullRequestComment $comment, string $body): void
    {
        $connection = $row->gitConnection;

        if ($comment->comment_id !== null
            && $this->api->send($connection, $this->commentUpdateMethod(), $this->commentUpdatePath($row, $comment->pr_number, $comment->comment_id), ['body' => $body], accept: [404])->status() !== 404) {
            return;
        }

        $response = $this->api->send($connection, 'POST', $this->commentCreatePath($row, $comment->pr_number), ['body' => $body]);
        $comment->comment_id = JsonObject::decode($response->body())->int('id');
        $comment->save();
    }

    private function previewRepository(Preview $preview): ?GitRepository
    {
        if ($preview->git_connection_id === null || $preview->provider->gitProvider() !== $this->kind()) {
            return null;
        }

        $row = GitRepository::query()
            ->with('gitConnection')
            ->where('team_id', $preview->team_id)
            ->where('git_connection_id', $preview->git_connection_id)
            ->where('external_id', $preview->repository_id)
            ->first();

        return $row instanceof GitRepository && $row->gitConnection->isActive() ? $row : null;
    }

    /**
     * @throws RepositoryFileUnavailable
     */
    private function usable(RepositoryRef $repository): GitRepository
    {
        $row = $this->row($repository);

        if (! $row instanceof GitRepository || ! $row->gitConnection->isActive()) {
            throw new RepositoryFileUnavailable("launchroom can't access {$repository->fullName}. Check its connection in Settings → Git.");
        }

        return $row;
    }

    /**
     * The repository row within the ref's team and connection (docs/09 R18).
     */
    protected function row(RepositoryRef $repository): ?GitRepository
    {
        if ($repository->connectionId === null || $repository->provider !== $this->kind()) {
            return null;
        }

        return GitRepository::query()
            ->with('gitConnection')
            ->where('team_id', $repository->teamId)
            ->where('git_connection_id', $repository->connectionId)
            ->where('external_id', $repository->repositoryId)
            ->whereRelation('gitConnection', 'provider', $this->kind()->value)
            ->first();
    }

    protected static function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1).'…' : $value;
    }
}
