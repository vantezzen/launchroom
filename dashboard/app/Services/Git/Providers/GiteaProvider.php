<?php

declare(strict_types=1);

namespace App\Services\Git\Providers;

use App\Data\Git\CommitData;
use App\Data\Git\FilePullRequest;
use App\Data\Git\GitBranchData;
use App\Data\Git\PushPayload;
use App\Data\Git\RepositoryRef;
use App\Data\Git\RepositoryTree;
use App\Enums\Git\DeploymentReportState;
use App\Enums\Git\GitProviderKind;
use App\Enums\Git\GitServerKind;
use App\Enums\Git\PullRequestAction;
use App\Events\Git\PullRequestEventReceived;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\Git\GitProviderRequestFailed;
use App\Models\Git\GitConnection;
use App\Models\Git\GitRepository;
use App\Support\Git\GitRef;
use App\Support\Git\GitWebPath;
use App\Support\Git\ProviderAvatar;
use App\Support\Git\PushedFiles;
use App\Support\Json\JsonObject;
use Illuminate\Http\Client\Response;
use SensitiveParameter;
use Throwable;

/**
 * Gitea and Forgejo (API v1; docs/19). Repositories are addressed by
 * `owner/name` as the API returned it for this connection; matching uses
 * the numeric id.
 */
final class GiteaProvider extends ApiGitProvider
{
    private const int MAX_TREE_PAGES = 10;

    public function kind(): GitProviderKind
    {
        return GitProviderKind::Gitea;
    }

    public function webhookUrl(GitConnection $connection): string
    {
        return self::deliveryUrl(route('git.webhooks.gitea', ['connection' => $connection->public_id]));
    }

    protected function apiPath(): string
    {
        return '/api/v1';
    }

    protected function oauthTokenPath(): string
    {
        return '/login/oauth/access_token';
    }

    protected function oauthAuthorizePath(): string
    {
        return '/login/oauth/authorize';
    }

    protected function oauthScope(): ?string
    {
        return null;
    }

    protected function accountLogin(JsonObject $account): ?string
    {
        return $account->stringOrNull('login') ?? $account->stringOrNull('username');
    }

    /**
     * Gitea and Forgejo are told apart by their version string.
     */
    public function probeUrl(string $origin): string
    {
        return $this->apiRoot($origin).'/version';
    }

    public function recognize(Response $response, string $origin): GitServerKind
    {
        $version = $response->json('version');

        if (! $response->successful() || ! is_string($version)) {
            throw DomainRuleViolation::on('baseUrl', "{$origin} doesn’t look like a Gitea or Forgejo server.");
        }

        return str_contains(strtolower($version), 'forgejo') || str_contains($version, '+gitea') ? GitServerKind::Forgejo : GitServerKind::Gitea;
    }

    /**
     * Gitea/Forgejo send an HMAC-SHA256 hex of the raw body.
     */
    protected function signatureMatches(string $rawBody, string $signature, #[SensitiveParameter] string $secret): bool
    {
        return preg_match('/^[0-9a-fA-F]{64}$/D', $signature) === 1 && hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($signature));
    }

    public function webhookEvent(?string $header): ?string
    {
        $event = $header ?? '';

        if (preg_match('/^[a-z_]{1,64}$/D', $event) !== 1) {
            return null;
        }

        // Gitea sends pull_request_sync / pull_request_label as their own events.
        return str_starts_with($event, 'pull_request') ? 'pull_request' : $event;
    }

    public function webhookSummary(JsonObject $payload): array
    {
        return [$payload->objectOrNull('repository')?->intOrNull('id'), $payload->stringOrNull('action')];
    }

    public function parseWebhook(GitConnection $connection, string $event, JsonObject $payload): PushPayload|PullRequestEventReceived|null
    {
        return match ($event) {
            'push', 'tag_push' => $this->push($connection, $payload),
            'pull_request' => $this->pullRequest($connection, $payload),
            default => null,
        };
    }

    private function repository(GitConnection $connection, JsonObject $payload): GitRepository
    {
        $source = $payload->object('repository');

        return $this->rememberDelivered($connection, $source->int('id'), $source->string('full_name'), $source->stringOrNull('default_branch'), $source->bool('private'));
    }

    private function push(GitConnection $connection, JsonObject $payload): PushPayload
    {
        $repository = $this->repository($connection, $payload);
        $commits = $payload->objects('commits');
        $after = $payload->string('after');
        $head = $payload->objectOrNull('head_commit');

        foreach ($commits as $commit) {
            if ($head === null && $commit->stringOrNull('id') === $after) {
                $head = $commit;
            }
        }

        $author = $head?->objectOrNull('author');
        $sender = $payload->objectOrNull('sender');

        return new PushPayload($repository->external_id, $payload->string('ref'), $payload->stringOrNull('before') ?? GitRef::ZERO_SHA, $after, new CommitData(
            sha: $after,
            message: $head?->stringOrNull('message') ?? '',
            authorName: $author?->stringOrNull('name'),
            authorEmail: $author?->stringOrNull('email'),
            authorLogin: $author?->stringOrNull('username') ?? $sender?->stringOrNull('login'),
            authorAvatarUrl: ProviderAvatar::url($sender?->stringOrNull('avatar_url'), $connection->origin()),
        ), PushedFiles::of($commits, $payload->intOrNull('total_commits')), false);
    }

    private function pullRequest(GitConnection $connection, JsonObject $payload): ?PullRequestEventReceived
    {
        $repository = $this->repository($connection, $payload);
        $pullRequest = $payload->object('pull_request');
        $head = $pullRequest->object('head');
        $base = $pullRequest->object('base');
        $baseRepoId = $base->objectOrNull('repo')?->intOrNull('id') ?? $repository->external_id;
        $headRepoId = $head->objectOrNull('repo')?->intOrNull('id');
        $headSha = $head->stringOrNull('sha') ?? '';

        if ($baseRepoId !== $repository->external_id || ! GitRef::isSha($headSha)) {
            return null;
        }

        $action = match ($payload->stringOrNull('action')) {
            'opened' => PullRequestAction::Opened,
            'reopened' => PullRequestAction::Reopened,
            'synchronized' => PullRequestAction::Synchronize,
            'closed' => PullRequestAction::Closed,
            'edited' => PullRequestAction::Edited,
            'label_updated' => PullRequestAction::Labeled,
            'label_cleared' => PullRequestAction::Unlabeled,
            default => null,
        };

        if ($action === null) {
            return null;
        }

        $labels = array_map(static fn (JsonObject $label): string => $label->string('name'), $pullRequest->objects('labels'));
        $isFork = $headRepoId !== $baseRepoId;
        $author = $pullRequest->objectOrNull('user')?->stringOrNull('login') ?? 'unknown';
        $label = $action === PullRequestAction::Labeled && in_array('preview', $labels, true) ? 'preview' : null;
        $sender = $label !== null ? $payload->objectOrNull('sender')?->stringOrNull('login') : null;

        return new PullRequestEventReceived(
            $connection,
            $repository->external_id,
            $action,
            $payload->intOrNull('number') ?? $pullRequest->int('number'),
            $pullRequest->stringOrNull('title') ?? '',
            $headSha,
            $head->string('ref'),
            $base->string('ref'),
            $headRepoId,
            $baseRepoId,
            $isFork,
            mb_substr($author, 0, 100),
            $isFork && $action->isCodeEvent() ? $this->authorPermission($connection, $repository, $author) : null,
            $pullRequest->bool('draft'),
            $pullRequest->bool('merged'),
            $labels,
            $label,
            $sender,
            $isFork && $sender !== null ? $this->authorPermission($connection, $repository, $sender) : null,
        );
    }

    protected function searchRequest(string $search): array
    {
        return ['/repos/search', [
            'limit' => 50,
            'sort' => 'updated',
            'order' => 'desc',
            'private' => 'true',
            ...($search === '' ? [] : ['q' => $search]),
        ]];
    }

    protected function parseRepositories(Response $response): array
    {
        $rows = [];

        foreach (JsonObject::decode($response->body())->objects('data') as $repository) {
            $id = $repository->intOrNull('id');
            $name = $repository->stringOrNull('full_name');
            $permissions = $repository->objectOrNull('permissions');

            // Only repositories the user can push to (like GitLab's Developer access).
            if ($id === null || $name === null || preg_match('/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/D', $name) !== 1
                || $permissions?->bool('push') !== true && $permissions?->bool('admin') !== true) {
                continue;
            }

            $rows[] = [
                'external_id' => $id,
                'full_name' => $name,
                'default_branch' => $repository->stringOrNull('default_branch'),
                'private' => $repository->bool('private'),
                'archived' => $repository->bool('archived'),
                'pushed_at' => $repository->timestampOrNull('updated_at'),
            ];
        }

        return $rows;
    }

    protected function repositoryPath(GitRepository $repository): string
    {
        [$owner, $name] = explode('/', $repository->full_name, 2) + [1 => ''];

        return '/repos/'.rawurlencode($owner).'/'.rawurlencode($name);
    }

    protected function parseBranches(GitRepository $repository, Response $response): array
    {
        return array_map(static fn (JsonObject $branch): GitBranchData => new GitBranchData(
            $branch->string('name'),
            $branch->object('commit')->string('id'),
            $branch->bool('protected'),
            $branch->string('name') === $repository->default_branch,
        ), JsonObject::decodeList($response->body()));
    }

    protected function branchesPath(GitRepository $repository): string
    {
        return $this->repositoryPath($repository).'/branches';
    }

    protected function branchPath(GitRepository $repository, string $branch): string
    {
        return $this->repositoryPath($repository).'/branches/'.rawurlencode($branch);
    }

    protected function parseHead(GitConnection $connection, JsonObject $branch): CommitData
    {
        $commit = $branch->object('commit');
        $author = $commit->objectOrNull('author');

        return new CommitData(
            sha: $commit->string('id'),
            message: $commit->stringOrNull('message') ?? '',
            authorName: $author?->stringOrNull('name'),
            authorEmail: $author?->stringOrNull('email'),
            authorLogin: $author?->stringOrNull('username'),
        );
    }

    protected function treeListing(GitConnection $connection, GitRepository $repository, string $ref): RepositoryTree
    {
        $paths = [];

        for ($page = 1; $page <= self::MAX_TREE_PAGES; $page++) {
            $json = JsonObject::decode($this->api->send($connection, 'GET', $this->repositoryPath($repository).'/git/trees/'.rawurlencode($ref), query: ['recursive' => 'true', 'per_page' => 1000, 'page' => $page])->body());

            foreach ($json->objects('tree') as $entry) {
                if ($entry->stringOrNull('type') === 'blob' && ($path = $entry->stringOrNull('path')) !== null) {
                    $paths[] = $path;
                }
            }

            if (! $json->bool('truncated')) {
                return new RepositoryTree($paths, true);
            }
        }

        return new RepositoryTree($paths, false);
    }

    protected function fileRequest(GitRepository $repository, string $path, string $ref): array
    {
        return [$this->repositoryPath($repository).'/raw/'.implode('/', array_map(rawurlencode(...), explode('/', $path))), ['ref' => $ref]];
    }

    protected function listChangedFiles(GitConnection $connection, GitRepository $repository, int $number, string $baseRef, string $headSha): ?array
    {
        $files = [];

        for ($page = 1; $page <= 3; $page++) {
            $items = JsonObject::decodeList($this->api->send($connection, 'GET', $this->repositoryPath($repository).'/pulls/'.$number.'/files', query: ['limit' => 100, 'page' => $page])->body());

            foreach ($items as $file) {
                foreach (['filename', 'previous_filename'] as $key) {
                    if (($path = $file->stringOrNull($key)) !== null && $path !== '') {
                        $files[] = $path;
                    }
                }
            }

            if (count($items) < 100) {
                return $files;
            }
        }

        return null;
    }

    protected function pullRequestState(GitConnection $connection, GitRepository $repository, int $number): bool
    {
        $response = $this->api->send($connection, 'GET', $this->repositoryPath($repository).'/pulls/'.$number, accept: [404]);

        return $response->status() === 404 ? false : JsonObject::decode($response->body())->stringOrNull('state') === 'open';
    }

    public function compareUrl(RepositoryRef $repository, string $from, string $to): ?string
    {
        $row = $this->row($repository);

        return $row instanceof GitRepository ? $row->gitConnection->origin().'/'.$row->full_name.'/compare/'.$from.'...'.$to : null;
    }

    public function repositoryUrl(RepositoryRef $repository, ?string $branch = null): ?string
    {
        $row = $this->row($repository);

        return $row instanceof GitRepository ? $row->gitConnection->origin().'/'.$row->full_name.($branch === null ? '' : '/src/branch/'.GitWebPath::branch($branch)) : null;
    }

    public function fileUrl(RepositoryRef $repository, string $path, string $ref, ?int $line = null): ?string
    {
        $row = $this->row($repository);

        return $row instanceof GitRepository
            ? $row->gitConnection->origin().'/'.$row->full_name.(GitRef::isSha($ref) ? '/src/commit/' : '/src/branch/').GitWebPath::branch($ref).'/'.GitWebPath::path($path).($line === null ? '' : "#L{$line}")
            : null;
    }

    protected function statusRequest(GitRepository $repository, string $sha, string $context, string $state, string $description, ?string $url): array
    {
        return [$this->repositoryPath($repository).'/statuses/'.$sha, [
            'state' => $state,
            'context' => $context,
            'description' => $description,
            ...($url === null ? [] : ['target_url' => $url]),
        ]];
    }

    protected function statusState(DeploymentReportState $state): string
    {
        return $state->giteaStatus();
    }

    protected function commentCreatePath(GitRepository $repository, int $number): string
    {
        return $this->repositoryPath($repository).'/issues/'.$number.'/comments';
    }

    protected function commentUpdatePath(GitRepository $repository, int $number, int $commentId): string
    {
        return $this->repositoryPath($repository).'/issues/comments/'.$commentId;
    }

    protected function commentUpdateMethod(): string
    {
        return 'PATCH';
    }

    protected function hookRequest(GitRepository $repository, string $url, string $secret): array
    {
        return [$this->repositoryPath($repository).'/hooks', [
            'type' => 'gitea',
            'active' => true,
            'events' => ['push', 'pull_request', 'pull_request_sync', 'pull_request_label'],
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret],
        ]];
    }

    public function authorPermission(GitConnection $connection, GitRepository $repository, string $user): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,39}$/D', $user) !== 1) {
            return 'unknown';
        }

        try {
            $response = $this->api->send($connection, 'GET', $this->repositoryPath($repository).'/collaborators/'.rawurlencode($user).'/permission', accept: [403, 404]);

            if ($response->status() !== 200) {
                return 'none';
            }

            $permission = JsonObject::decode($response->body())->stringOrNull('permission');
        } catch (Throwable) {
            return 'unknown';
        }

        return match ($permission) {
            'owner', 'admin' => 'admin',
            'write' => 'write',
            'read' => 'read',
            default => 'none',
        };
    }

    protected function proposeFile(GitRepository $repository, FilePullRequest $request, string $base): string
    {
        $repo = $this->repositoryPath($repository);
        $file = $repo.'/contents/'.implode('/', array_map(rawurlencode(...), explode('/', $request->path)));
        $existing = $this->api->send($repository->gitConnection, 'GET', $file, query: ['ref' => $base], accept: [404]);

        if ($existing->successful()) {
            throw new GitProviderRequestFailed("{$request->path} already exists on {$base}.", 409);
        }

        $this->api->send($repository->gitConnection, 'POST', $file, [
            'content' => base64_encode($request->content),
            'message' => $request->commitMessage,
            'branch' => $base,
            'new_branch' => $request->branch,
        ]);

        $pull = JsonObject::decode($this->api->send($repository->gitConnection, 'POST', $repo.'/pulls', [
            'head' => $request->branch,
            'base' => $base,
            'title' => $request->title,
            'body' => $request->body,
        ])->body());

        return $pull->string('html_url');
    }
}
