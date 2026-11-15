<?php

declare(strict_types=1);

namespace App\Services\Git\Providers\GitHub;

use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubPullRequestComment;
use App\Models\Git\GitHubRepository;
use App\Services\Git\Feedback\PullRequestThread;
use App\Services\Git\Providers\GitHub\Exceptions\GitHubRequestFailed;
use App\Support\Json\JsonObject;
use Illuminate\Http\Client\Response;

/**
 * Calls under `/repos/{owner}/{repo}` with a reporting-scoped token
 * (deployments, checks, statuses, pull requests; one repository).
 */
final readonly class RepositoryCall
{
    public function __construct(
        private GitHubAppClient $client,
        private GitHubInstallation $installation,
        private GitHubRepository $repository,
    ) {}

    /**
     * @param  'GET'|'POST'|'PATCH'|'DELETE'  $method
     * @param  array<array-key, mixed>|null  $json
     * @param  list<int>  $accept
     *
     * @throws GitHubRequestFailed
     */
    public function send(string $method, string $path, ?array $json = null, array $accept = []): Response
    {
        return $this->client->asInstallation(
            $this->installation->installation_id,
            TokenScope::report($this->repository->github_repo_id),
            $method,
            "/repos/{$this->repository->full_name}{$path}",
            $json,
            accept: $accept,
        );
    }

    public function pullRequestThread(int $number): PullRequestThread
    {
        $key = ['github_installation_id' => $this->installation->id, 'github_repo_id' => $this->repository->github_repo_id, 'pr_number' => $number];

        return new PullRequestThread(
            "launchroom:github:pr-comment:{$this->installation->id}:{$this->repository->github_repo_id}:{$number}",
            fn (bool $create): ?GitHubPullRequestComment => $create
                ? GitHubPullRequestComment::query()->firstOrCreate($key, ['team_id' => $this->installation->team_id])
                : GitHubPullRequestComment::query()->where($key)->first(),
            fn (int $commentId, string $body): bool => $this->send('PATCH', "/issues/comments/{$commentId}", ['body' => $body], [404])->status() !== 404,
            fn (string $body): int => JsonObject::decode($this->send('POST', "/issues/{$number}/comments", ['body' => $body])->body())->int('id'),
        );
    }
}
