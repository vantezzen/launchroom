<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\CommitData;
use App\Enums\Git\GitEventSource;
use App\Events\Git\GitPushReceived;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRefHead;
use App\Services\GitHub\GitHubClientFactory;
use App\Services\GitHub\JsonObject;
use App\Services\GitHub\TokenScope;
use App\Support\Git\GitHubAvatar;
use App\Support\Git\GitRef;
use App\Support\Git\SkipMarkers;

/**
 * Polling fallback for one ref (docs/06 §2): a conditional
 * `GET /repos/{repo}/commits/{ref}` with the stored ETag (a 304 costs no rate
 * limit). A changed head emits the same {@see GitPushReceived} a webhook would.
 * The first observation of a ref only records a baseline.
 */
final readonly class PollTrackedRef
{
    public function __construct(
        private GitHubClientFactory $clients,
        private RecordRefHead $recordRefHead,
    ) {}

    /**
     * @return bool whether an event was emitted
     */
    public function handle(GitHubInstallation $installation, int $repositoryId, string $ref): bool
    {
        $repository = $installation->repositories()->where('github_repo_id', $repositoryId)->first();

        if ($repository === null || ! GitRef::isValid($ref) || ! $installation->isActive()) {
            return false;
        }

        $head = GitHubRefHead::query()
            ->where(['github_installation_id' => $installation->id, 'github_repo_id' => $repositoryId, 'ref' => $ref])
            ->first();

        $response = $this->clients->for($installation->app)->asInstallation(
            $installation->installation_id,
            TokenScope::read($repositoryId),
            'GET',
            "/repos/{$repository->full_name}/commits/".rawurlencode(GitRef::shortName($ref)),
            headers: $head?->etag !== null ? ['If-None-Match' => $head->etag] : [],
            accept: [304, 404, 422],
        );

        if ($response->status() !== 200) {
            // 304: unchanged. 404/422: the ref doesn't exist (yet).
            $head?->forceFill(['last_polled_at' => now()])->save();

            return false;
        }

        $json = JsonObject::decode($response->body());
        $sha = $json->string('sha');

        if (! GitRef::isSha($sha)) {
            return false;
        }

        $etag = $response->header('ETag');
        $update = $this->recordRefHead->handle($installation, $repositoryId, $ref, $sha, $etag === '' ? null : mb_substr($etag, 0, 255), polled: true);

        if (! $update->changed || $update->previous === null) {
            return false;
        }

        $commit = $json->object('commit');
        $author = $commit->objectOrNull('author');
        $headCommit = new CommitData(
            sha: $sha,
            message: $commit->stringOrNull('message') ?? '',
            authorName: $author?->stringOrNull('name'),
            authorEmail: $author?->stringOrNull('email'),
            authorLogin: $json->objectOrNull('author')?->stringOrNull('login'),
            authorAvatarUrl: GitHubAvatar::url($json->objectOrNull('author')?->stringOrNull('avatar_url'), $json->objectOrNull('author')?->stringOrNull('login')),
        );

        if (SkipMarkers::matches($headCommit->message)) {
            return false;
        }

        event(new GitPushReceived($installation, $repositoryId, $ref, $update->previous, $sha, $headCommit, null, false, GitEventSource::Polling));

        return true;
    }
}
