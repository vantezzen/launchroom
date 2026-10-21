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
