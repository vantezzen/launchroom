<?php

declare(strict_types=1);

namespace App\Services\Git\Providers;

use App\Data\Git\CommitData;
use App\Data\Git\FilePullRequest;
use App\Data\Git\GitBranchData;
use App\Data\Git\RepositoryRef;
use App\Data\Git\RepositoryTree;
use App\Enums\Git\DeploymentReportState;
use App\Enums\Git\GitProviderKind;
use App\Models\Git\GitConnection;
use App\Models\Git\GitRepository;
use App\Services\Git\Exceptions\GitProviderRequestFailed;
use App\Services\GitHub\JsonObject;
use Illuminate\Http\Client\Response;
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

    protected function statusRequest(GitRepository $repository, string $sha, string $context, string $state, string $description, ?string $url): array
    {
        return [$this->repositoryPath($repository).'/statuses/'.$sha, [
            'state' => $state,
            'name' => $context,
            'description' => $description,
            ...($url === null ? [] : ['target_url' => $url]),
        ]];
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
