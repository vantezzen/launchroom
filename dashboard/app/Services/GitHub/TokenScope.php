<?php

declare(strict_types=1);

namespace App\Services\GitHub;

/**
 * What an installation token may do: one repository and the smallest
 * permission set for the job (docs/09 R19). The app itself holds only the
 * permissions of the manifest; tokens narrow them further.
 */
final readonly class TokenScope
{
    /**
     * @param  list<int>|null  $repositoryIds  null only for installation-wide metadata reads
     * @param  array<string, 'read'|'write'>  $permissions
     */
    private function __construct(
        public ?array $repositoryIds,
        public array $permissions,
    ) {}

    /**
     * Clone one repository for a build.
     */
    public static function clone(int $repositoryId): self
    {
        return new self([$repositoryId], ['contents' => 'read', 'metadata' => 'read']);
    }

    /**
     * Read branches and commits of one repository (repo picker, polling).
     */
    public static function read(int $repositoryId): self
    {
        return new self([$repositoryId], ['contents' => 'read', 'metadata' => 'read']);
    }

    /**
     * Report deployment status: Deployments API, check runs, the PR comment,
     * collaborator permission checks.
     */
    public static function report(int $repositoryId): self
    {
        return new self([$repositoryId], [
            'checks' => 'write',
            'deployments' => 'write',
            'metadata' => 'read',
            'pull_requests' => 'write',
            'statuses' => 'write',
        ]);
    }

    /**
     * Propose a file as a pull request (docs/20 §5): needs the App's
     * Contents permission set to read and write, which the default manifest
     * doesn't request; GitHub refuses the token otherwise.
     */
    public static function propose(int $repositoryId): self
    {
        return new self([$repositoryId], ['contents' => 'write', 'metadata' => 'read', 'pull_requests' => 'write']);
    }

    /**
     * List the installation's repositories. `GET /installation/repositories`
     * only returns the repositories a token covers, so this one can't be
     * narrowed to a repository; it can only read metadata.
     */
    public static function listRepositories(): self
    {
        return new self(null, ['metadata' => 'read']);
    }

    public function cacheKey(): string
    {
        $permissions = $this->permissions;
        ksort($permissions);

        return hash('sha256', json_encode([$this->repositoryIds, $permissions], JSON_THROW_ON_ERROR));
    }

    /**
     * Body for `POST /app/installations/{id}/access_tokens`.
     *
     * @return array<string, mixed>
     */
    public function requestBody(): array
    {
        $body = ['permissions' => $this->permissions];

        if ($this->repositoryIds !== null) {
            $body['repository_ids'] = $this->repositoryIds;
        }

        return $body;
    }
}
