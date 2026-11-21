<?php

declare(strict_types=1);

namespace App\Services\Git\Providers;

use App\Data\Git\CommitCheckData;
use App\Data\Git\CommitData;
use App\Data\Git\FilePullRequest;
use App\Data\Git\GitBranchData;
use App\Data\Git\PushPayload;
use App\Data\Git\RepositoryRef;
use App\Data\Git\RepositoryTree;
use App\Enums\Git\CommitCheckState;
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
 * GitLab (gitlab.com and self-managed, API v4; docs/19). Projects are
 * addressed by numeric id, never by path.
 */
final class GitLabProvider extends ApiGitProvider
{
    /** Developer access: can push, so it is a project the user works on. */
    private const int MIN_ACCESS_LEVEL = 30;

    private const int MAX_TREE_PAGES = 20;

    public function kind(): GitProviderKind
    {
        return GitProviderKind::GitLab;
    }

    public function webhookUrl(GitConnection $connection): string
    {
        return self::deliveryUrl(route('git.webhooks.gitlab', ['connection' => $connection->public_id]));
    }

    protected function apiPath(): string
    {
        return '/api/v4';
    }

    protected function oauthTokenPath(): string
    {
        return '/oauth/token';
    }

    protected function oauthAuthorizePath(): string
    {
        return '/oauth/authorize';
    }

    protected function oauthScope(): string
    {
        return 'api';
    }

    protected function accountLogin(JsonObject $account): ?string
    {
        return $account->stringOrNull('username');
    }

    public function probeUrl(string $origin): string
    {
        return $this->apiRoot($origin).'/projects?per_page=1';
    }

    public function recognize(Response $response, string $origin): GitServerKind
    {
        if (! in_array($response->status(), [200, 401, 403], true) || ($response->header('X-Gitlab-Meta') === '' && ! is_array($response->json()))) {
            throw DomainRuleViolation::on('baseUrl', "{$origin} doesn’t look like a GitLab server.");
        }

        return GitServerKind::GitLab;
    }

    /**
     * GitLab sends the secret itself in `X-Gitlab-Token`.
     */
    protected function signatureMatches(string $rawBody, string $signature, #[SensitiveParameter] string $secret): bool
    {
        return hash_equals($secret, $signature);
    }

    public function webhookEvent(?string $header): ?string
    {
        $event = $header ?? '';

        return match ($event) {
            'Push Hook' => 'push',
            'Tag Push Hook' => 'tag_push',
            'Merge Request Hook' => 'merge_request',
            default => preg_match('/^[A-Za-z ]{1,64}$/D', $event) === 1 ? strtolower(str_replace(' ', '_', $event)) : null,
        };
    }

    public function webhookSummary(JsonObject $payload): array
    {
        return [$payload->objectOrNull('project')?->intOrNull('id'), $payload->objectOrNull('object_attributes')?->stringOrNull('action')];
    }

    public function parseWebhook(GitConnection $connection, string $event, JsonObject $payload): PushPayload|PullRequestEventReceived|null
    {
        return match ($event) {
            'push', 'tag_push' => $this->push($connection, $payload),
            'merge_request' => $this->mergeRequest($connection, $payload),
            default => null,
        };
    }

    private function project(GitConnection $connection, JsonObject $payload): GitRepository
    {
        $project = $payload->object('project');

        return $this->rememberDelivered($connection, $project->int('id'), $project->string('path_with_namespace'), $project->stringOrNull('default_branch'), $project->intOrNull('visibility_level') !== 20);
    }

    private function push(GitConnection $connection, JsonObject $payload): PushPayload
    {
        $repository = $this->project($connection, $payload);
        $commits = $payload->objects('commits');
        $after = $payload->string('after');
        $head = null;

        foreach ($commits as $commit) {
            if ($commit->stringOrNull('id') === $after) {
                $head = $commit;
            }
        }

        $author = $head?->objectOrNull('author');

        return new PushPayload($repository->external_id, $payload->string('ref'), $payload->string('before'), $after, new CommitData(
            sha: $after,
            message: $head?->stringOrNull('message') ?? '',
            authorName: $author?->stringOrNull('name') ?? $payload->stringOrNull('user_name'),
            authorEmail: $author?->stringOrNull('email'),
            authorLogin: $payload->stringOrNull('user_username'),
            authorAvatarUrl: ProviderAvatar::url($payload->stringOrNull('user_avatar'), $connection->origin()),
        ), PushedFiles::of($commits, $payload->intOrNull('total_commits_count')), false);
    }

    private function mergeRequest(GitConnection $connection, JsonObject $payload): ?PullRequestEventReceived
    {
        $repository = $this->project($connection, $payload);
        $mr = $payload->object('object_attributes');
        $targetId = $mr->int('target_project_id');
        $sourceId = $mr->intOrNull('source_project_id');
        $headSha = $mr->objectOrNull('last_commit')?->stringOrNull('id') ?? '';

        if ($targetId !== $repository->external_id || ! GitRef::isSha($headSha)) {
            return null;
        }

        $state = $mr->stringOrNull('state');
        $action = match ($mr->stringOrNull('action')) {
            'open' => PullRequestAction::Opened,
            'reopen' => PullRequestAction::Reopened,
            'update' => $mr->stringOrNull('oldrev') !== null ? PullRequestAction::Synchronize : PullRequestAction::Edited,
            'close', 'merge' => PullRequestAction::Closed,
            default => null,
        };

        if ($action === null) {
            return null;
        }

        $user = $payload->objectOrNull('user');
        $labels = array_map(static fn (JsonObject $label): string => $label->string('title'), $payload->objects('labels'));
        $isFork = $sourceId !== $targetId;

        // Approval by label (docs/09 R20): `preview` newly applied by someone with write access.
        $label = null;
        $changes = $payload->objectOrNull('changes')?->objectOrNull('labels');

        if ($action === PullRequestAction::Edited && $changes instanceof JsonObject) {
            $before = array_map(static fn (JsonObject $l): string => $l->string('title'), $changes->objects('previous'));
            $after = array_map(static fn (JsonObject $l): string => $l->string('title'), $changes->objects('current'));

            if (in_array('preview', $after, true) && ! in_array('preview', $before, true)) {
                $action = PullRequestAction::Labeled;
                $label = 'preview';
            }
        }

        $sender = $user?->stringOrNull('username');
        $authorId = (string) ($mr->intOrNull('author_id') ?? 0);

        return new PullRequestEventReceived(
            $connection,
            $repository->external_id,
            $action,
            $mr->int('iid'),
            $mr->stringOrNull('title') ?? '',
            $headSha,
            $mr->string('source_branch'),
            $mr->string('target_branch'),
            $sourceId,
            $targetId,
            $isFork,
            mb_substr($sender ?? 'unknown', 0, 100),
            $isFork && $action->isCodeEvent() ? $this->authorPermission($connection, $repository, $authorId) : null,
            $mr->bool('draft') || $mr->bool('work_in_progress'),
            $state === 'merged' || $mr->stringOrNull('action') === 'merge',
            $labels,
            $label,
            $label !== null ? $sender : null,
            $isFork && $label !== null ? $this->authorPermission($connection, $repository, (string) ($user?->intOrNull('id') ?? 0)) : null,
        );
    }

    protected function searchRequest(string $search): array
    {
        return ['/projects', [
            'membership' => 'true',
            'min_access_level' => self::MIN_ACCESS_LEVEL,
            'order_by' => 'last_activity_at',
            'sort' => 'desc',
            'per_page' => 50,
            'simple' => 'true',
            ...($search === '' ? [] : ['search' => $search, 'search_namespaces' => 'true']),
        ]];
    }

    protected function parseRepositories(Response $response): array
    {
        $rows = [];

        foreach (JsonObject::decodeList($response->body()) as $project) {
            $id = $project->intOrNull('id');
            $path = $project->stringOrNull('path_with_namespace');

            if ($id === null || $path === null || preg_match('/^[A-Za-z0-9_.-]{1,100}(?:\/[A-Za-z0-9_.-]{1,100}){1,7}$/D', $path) !== 1) {
                continue;
            }

            $rows[] = [
                'external_id' => $id,
                'full_name' => $path,
                'default_branch' => $project->stringOrNull('default_branch'),
                'private' => $project->stringOrNull('visibility') !== 'public',
                'archived' => $project->bool('archived'),
                'pushed_at' => $project->timestampOrNull('last_activity_at'),
            ];
        }

        return $rows;
    }

    protected function repositoryPath(GitRepository $repository): string
    {
        return '/projects/'.$repository->external_id;
    }

    protected function parseBranches(GitRepository $repository, Response $response): array
    {
        return array_map(static fn (JsonObject $branch): GitBranchData => new GitBranchData(
            $branch->string('name'),
            $branch->object('commit')->string('id'),
            $branch->bool('protected'),
            $branch->bool('default') || $branch->string('name') === $repository->default_branch,
        ), JsonObject::decodeList($response->body()));
    }

    protected function branchesPath(GitRepository $repository): string
    {
        return $this->repositoryPath($repository).'/repository/branches';
    }

    protected function branchPath(GitRepository $repository, string $branch): string
    {
        return $this->repositoryPath($repository).'/repository/branches/'.rawurlencode($branch);
    }

    protected function parseHead(GitConnection $connection, JsonObject $branch): CommitData
    {
        $commit = $branch->object('commit');

        return new CommitData(
            sha: $commit->string('id'),
            message: $commit->stringOrNull('message') ?? '',
            authorName: $commit->stringOrNull('author_name'),
            authorEmail: $commit->stringOrNull('author_email'),
            authorLogin: null,
        );
    }

    protected function treeListing(GitConnection $connection, GitRepository $repository, string $ref): RepositoryTree
    {
        $paths = [];

        for ($page = 1; $page <= self::MAX_TREE_PAGES; $page++) {
            $response = $this->api->send($connection, 'GET', $this->repositoryPath($repository).'/repository/tree', query: ['recursive' => 'true', 'ref' => $ref, 'per_page' => 100, 'page' => $page]);
            $items = JsonObject::decodeList($response->body());

            foreach ($items as $entry) {
                if ($entry->stringOrNull('type') === 'blob' && ($path = $entry->stringOrNull('path')) !== null) {
                    $paths[] = $path;
                }
            }

            if (count($items) < 100) {
                return new RepositoryTree($paths, true);
            }
        }

        return new RepositoryTree($paths, false);
    }

    protected function fileRequest(GitRepository $repository, string $path, string $ref): array
    {
        return [$this->repositoryPath($repository).'/repository/files/'.rawurlencode($path).'/raw', ['ref' => $ref]];
    }

    protected function listChangedFiles(GitConnection $connection, GitRepository $repository, int $number, string $baseRef, string $headSha): ?array
    {
        $response = $this->api->send($connection, 'GET', $this->repositoryPath($repository).'/repository/compare', query: ['from' => $baseRef, 'to' => $headSha, 'straight' => 'false']);
        $json = JsonObject::decode($response->body());

        if ($json->bool('compare_timeout')) {
            return null;
        }

        $files = [];

        foreach ($json->objects('diffs') as $diff) {
            foreach (['old_path', 'new_path'] as $key) {
                if (($path = $diff->stringOrNull($key)) !== null) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    protected function pullRequestState(GitConnection $connection, GitRepository $repository, int $number): bool
    {
        $response = $this->api->send($connection, 'GET', $this->repositoryPath($repository).'/merge_requests/'.$number, accept: [404]);

        return $response->status() === 404 ? false : JsonObject::decode($response->body())->stringOrNull('state') === 'opened';
    }

    public function compareUrl(RepositoryRef $repository, string $from, string $to): ?string
    {
        $row = $this->row($repository);

        return $row instanceof GitRepository ? $row->gitConnection->origin().'/'.$row->full_name.'/-/compare/'.$from.'...'.$to : null;
    }

    public function repositoryUrl(RepositoryRef $repository, ?string $branch = null): ?string
    {
        $row = $this->row($repository);

        return $row instanceof GitRepository ? $row->gitConnection->origin().'/'.$row->full_name.($branch === null ? '' : '/-/tree/'.GitWebPath::branch($branch)) : null;
    }

    public function fileUrl(RepositoryRef $repository, string $path, string $ref, ?int $line = null): ?string
    {
        $row = $this->row($repository);

        return $row instanceof GitRepository
            ? $row->gitConnection->origin().'/'.$row->full_name.'/-/blob/'.GitWebPath::branch($ref).'/'.GitWebPath::path($path).($line === null ? '' : "#L{$line}")
            : null;
    }

    protected function statusRequest(GitRepository $repository, string $sha, string $context, string $state, string $description, ?string $url): array
    {
        return [$this->repositoryPath($repository).'/statuses/'.$sha, [
            'state' => $state,
            'name' => $context,
            'description' => $description,
            ...($url === null ? [] : ['target_url' => $url]),
        ]];
    }

    protected function checksRequest(GitRepository $repository, string $sha): array
    {
        // The latest status per name (`all` false): pipeline jobs and external statuses.
        return [$this->repositoryPath($repository).'/repository/commits/'.$sha.'/statuses', ['per_page' => 100]];
    }

    protected function parseChecks(string $body): array
    {
        $checks = [];

        foreach (JsonObject::decodeList($body) as $status) {
            $name = $status->stringOrNull('name') ?? '';

            if ($name === '') {
                continue;
            }

            $state = match ($status->stringOrNull('status')) {
                'success', 'skipped', 'manual' => CommitCheckState::Passed,
                // A job allowed to fail doesn't hold the deploy back.
                'failed' => $status->bool('allow_failure') ? CommitCheckState::Passed : CommitCheckState::Failed,
                'canceled' => CommitCheckState::Failed,
                default => CommitCheckState::Pending,
            };

            $checks[] = new CommitCheckData(mb_substr($name, 0, 255), $state, CommitCheckData::link($status->stringOrNull('target_url')));
        }

        return $checks;
    }

    protected function statusState(DeploymentReportState $state): string
    {
        return $state->gitLabStatus();
    }

    protected function commentCreatePath(GitRepository $repository, int $number): string
    {
        return $this->repositoryPath($repository).'/merge_requests/'.$number.'/notes';
    }

    protected function commentUpdatePath(GitRepository $repository, int $number, int $commentId): string
    {
        return $this->repositoryPath($repository).'/merge_requests/'.$number.'/notes/'.$commentId;
    }

    protected function commentUpdateMethod(): string
    {
        return 'PUT';
    }

    protected function hookRequest(GitRepository $repository, string $url, string $secret): array
    {
        return [$this->repositoryPath($repository).'/hooks', [
            'url' => $url,
            'token' => $secret,
            'push_events' => true,
            'tag_push_events' => true,
            'merge_requests_events' => true,
            'enable_ssl_verification' => str_starts_with($url, 'https://'),
        ]];
    }

    public function authorPermission(GitConnection $connection, GitRepository $repository, string $user): string
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $user) !== 1) {
            return 'unknown';
        }

        try {
            $response = $this->api->send($connection, 'GET', $this->repositoryPath($repository).'/members/all/'.$user, accept: [404]);

            if ($response->status() === 404) {
                return 'none';
            }

            $level = JsonObject::decode($response->body())->intOrNull('access_level') ?? 0;
        } catch (Throwable) {
            return 'unknown';
        }

        return match (true) {
            $level >= 50 => 'admin',
            $level >= 40 => 'maintain',
            $level >= 30 => 'write',
            $level >= 10 => 'read',
            default => 'none',
        };
    }

    protected function proposeFile(GitRepository $repository, FilePullRequest $request, string $base): string
    {
        $project = $this->repositoryPath($repository);
        $existing = $this->api->send($repository->gitConnection, 'GET', $project.'/repository/files/'.rawurlencode($request->path), query: ['ref' => $base], accept: [404]);

        if ($existing->successful()) {
            throw new GitProviderRequestFailed("{$request->path} already exists on {$base}.", 409);
        }

        $this->api->send($repository->gitConnection, 'POST', $project.'/repository/commits', [
            'branch' => $request->branch,
            'start_branch' => $base,
            'commit_message' => $request->commitMessage,
            'actions' => [['action' => 'create', 'file_path' => $request->path, 'content' => $request->content]],
        ]);

        $merge = JsonObject::decode($this->api->send($repository->gitConnection, 'POST', $project.'/merge_requests', [
            'source_branch' => $request->branch,
            'target_branch' => $base,
            'title' => $request->title,
            'description' => $request->body,
            'remove_source_branch' => true,
        ])->body());

        return $merge->string('web_url');
    }
}
