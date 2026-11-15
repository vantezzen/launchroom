<?php

declare(strict_types=1);

namespace App\Services\Git\Providers\GitHub;

use App\Data\Git\CommitData;
use App\Data\Git\PushPayload;
use App\Enums\Git\PullRequestAction;
use App\Events\Git\PullRequestEventReceived;
use App\Models\Git\GitHubInstallation;
use App\Services\Git\Providers\GitHub\Dto\RepositoryInfo;
use App\Support\Git\GitHubAvatar;
use App\Support\Git\GitRef;
use App\Support\Git\PushedFiles;
use App\Support\Json\JsonObject;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A verified GitHub App `push` or `pull_request` delivery as the neutral
 * push or pull request event, like GitLab's and Gitea's `parseWebhook`
 * (docs/19 §5). The delivered repository refreshes the installation's
 * repository cache. Fork detection compares numeric repository ids
 * (docs/09 R20); for fork PRs the author's permission on the base repository
 * is looked up so the preview gate can require write access.
 */
final readonly class GitHubWebhooks
{
    /** A failed permission lookup: no write access (PullRequestData::hasWriteAccess). */
    public const string PERMISSION_UNKNOWN = 'unknown';

    public function __construct(
        private GitHubRepositoryCache $repositories,
        private GitHubClientFactory $clients,
    ) {}

    public function parse(GitHubInstallation $installation, string $event, JsonObject $payload): PushPayload|PullRequestEventReceived|null
    {
        return match ($event) {
            'push' => $this->push($installation, $payload),
            'pull_request' => $this->pullRequest($installation, $payload),
            default => null,
        };
    }

    private function push(GitHubInstallation $installation, JsonObject $payload): PushPayload
    {
        $repository = RepositoryInfo::fromJson($payload->object('repository'));
        $this->repositories->sync($installation, [$repository]);

        $after = $payload->bool('deleted') ? GitRef::ZERO_SHA : $payload->string('after');
        $commit = $payload->objectOrNull('head_commit');
        $author = $commit?->objectOrNull('author');
        $login = $author?->stringOrNull('username');
        // The push payload's commit author has no avatar; the sender does,
        // and usually is that author.
        $sender = $payload->objectOrNull('sender');
        $senderAvatar = $login !== null && strcasecmp($sender?->stringOrNull('login') ?? '', $login) === 0 ? $sender?->stringOrNull('avatar_url') : null;

        return new PushPayload(
            $repository->id,
            $payload->string('ref'),
            $payload->string('before'),
            $after,
            new CommitData(
                sha: $commit?->stringOrNull('id') ?? $after,
                message: $commit?->stringOrNull('message') ?? '',
                authorName: $author?->stringOrNull('name'),
                authorEmail: $author?->stringOrNull('email'),
                authorLogin: $login,
                authorAvatarUrl: GitHubAvatar::url($senderAvatar, $login),
            ),
            PushedFiles::of($payload->objects('commits')),
            $payload->bool('forced'),
        );
    }

    private function pullRequest(GitHubInstallation $installation, JsonObject $payload): ?PullRequestEventReceived
    {
        $repository = RepositoryInfo::fromJson($payload->object('repository'));
        $this->repositories->sync($installation, [$repository]);

        $pullRequest = $payload->object('pull_request');
        $head = $pullRequest->object('head');
        $base = $pullRequest->object('base');
        $baseRepoId = $base->object('repo')->int('id');
        // A PR from a deleted fork has `head.repo: null`.
        $headRepoId = $head->objectOrNull('repo')?->intOrNull('id');
        $headSha = $head->string('sha');

        if (! GitRef::isSha($headSha) || $baseRepoId !== $repository->id) {
            return null;
        }

        $isFork = $headRepoId !== $baseRepoId;
        $action = PullRequestAction::tryFrom($payload->string('action')) ?? PullRequestAction::Other;
        $author = $pullRequest->object('user')->string('login');

        // Fork approval by label (docs/09 R20): who applied the label matters.
        $label = $action === PullRequestAction::Labeled ? $payload->objectOrNull('label')?->stringOrNull('name') : null;
        $sender = $label !== null ? $payload->objectOrNull('sender')?->stringOrNull('login') : null;

        return new PullRequestEventReceived(
            $installation,
            $repository->id,
            $action,
            $payload->int('number'),
            $pullRequest->stringOrNull('title') ?? '',
            $headSha,
            $head->string('ref'),
            $base->string('ref'),
            $headRepoId,
            $baseRepoId,
            $isFork,
            $author,
            $isFork && $action->isCodeEvent() ? $this->permission($installation, $repository, $author) : null,
            $pullRequest->bool('draft'),
            $pullRequest->bool('merged'),
            array_map(static fn (JsonObject $label): string => $label->string('name'), $pullRequest->objects('labels')),
            $label,
            $sender,
            $isFork && $sender !== null ? $this->permission($installation, $repository, $sender) : null,
        );
    }

    private function permission(GitHubInstallation $installation, RepositoryInfo $repository, string $login): ?string
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
