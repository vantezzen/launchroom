<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Services\GitHub\Exceptions\GitHubRequestFailed;
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
}
