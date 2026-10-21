<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Events\Git\PullRequestEventReceived;
use App\Models\Git\GitHubInstallation;
use App\Services\GitHub\Dto\RepositoryInfo;
use App\Services\GitHub\GitHubClientFactory;
use App\Services\GitHub\JsonObject;
use App\Services\GitHub\TokenScope;
use App\Support\Git\GitRef;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `pull_request` webhook → {@see PullRequestEventReceived}. Fork detection
 * compares numeric repository ids (docs/09 R20); for fork PRs the author's
 * permission on the base repository is looked up so the preview gate can
 * require write access.
 */
final readonly class HandlePullRequestEvent
{
    private const array PERMISSION_CHECK_ACTIONS = ['opened', 'reopened', 'synchronize'];

    /** A failed permission lookup: no write access (PullRequestData::hasWriteAccess). */
    public const string PERMISSION_UNKNOWN = 'unknown';

    public function __construct(
        private SyncInstallationRepositories $syncRepositories,
        private GitHubClientFactory $clients,
    ) {}

    public function handle(GitHubInstallation $installation, JsonObject $payload): bool
    {
        $repository = RepositoryInfo::fromJson($payload->object('repository'));
        $this->syncRepositories->handle($installation, [$repository]);

        $pullRequest = $payload->object('pull_request');
        $head = $pullRequest->object('head');
        $base = $pullRequest->object('base');
        $baseRepoId = $base->object('repo')->int('id');
        // A PR from a deleted fork has `head.repo: null`.
        $headRepoId = $head->objectOrNull('repo')?->intOrNull('id');
        $headSha = $head->string('sha');

        if (! GitRef::isSha($headSha) || $baseRepoId !== $repository->id) {
            return false;
        }

        $isFork = $headRepoId !== $baseRepoId;
        $action = $payload->string('action');
        $author = $pullRequest->object('user')->string('login');

        // Fork approval by label (docs/09 R20): who applied the label matters.
        $label = $action === 'labeled' ? $payload->objectOrNull('label')?->stringOrNull('name') : null;
        $sender = $label !== null ? $payload->objectOrNull('sender')?->stringOrNull('login') : null;

        event(new PullRequestEventReceived($installation, $repository->id, $action, $payload->int('number'), $pullRequest->stringOrNull('title') ?? '', $headSha, $head->string('ref'), $base->string('ref'), $headRepoId, $baseRepoId, $isFork, $author, $isFork && in_array($action, self::PERMISSION_CHECK_ACTIONS, true)
            ? $this->authorPermission($installation, $repository, $author)
            : null, $pullRequest->bool('draft'), $pullRequest->bool('merged'), array_map(
                static fn (JsonObject $label): string => $label->string('name'),
                $pullRequest->objects('labels'),
            ), $label, $sender, $isFork && $sender !== null ? $this->authorPermission($installation, $repository, $sender) : null));

        return true;
    }

    private function authorPermission(GitHubInstallation $installation, RepositoryInfo $repository, string $login): ?string
    {
        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})(\[bot\])?$/D', $login) !== 1) {
            return null;
        }

        try {
            $response = $this->clients->for($installation->app)->asInstallation(
                $installation->installation_id,
                TokenScope::read($repository->id),
                'GET',
                "/repos/{$repository->fullName}/collaborators/".rawurlencode($login).'/permission',
                accept: [404],
            );

            return $response->status() === 404 ? 'none' : (JsonObject::decode($response->body())->stringOrNull('permission') ?? self::PERMISSION_UNKNOWN);
        } catch (Throwable $e) {
            Log::warning('Could not read a pull request author’s permission', ['repository' => $repository->id, 'error' => $e->getMessage()]);

            // Fail closed (docs/09 R20): the lookup failing replaces an
            // earlier permission instead of keeping it.
            return self::PERMISSION_UNKNOWN;
        }
    }
}
