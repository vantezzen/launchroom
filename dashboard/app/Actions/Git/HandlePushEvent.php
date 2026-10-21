<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\CommitData;
use App\Enums\Git\GitEventSource;
use App\Events\Git\GitPushReceived;
use App\Models\Git\GitHubInstallation;
use App\Services\GitHub\Dto\RepositoryInfo;
use App\Services\GitHub\JsonObject;
use App\Support\Git\GitHubAvatar;
use App\Support\Git\GitRef;
use App\Support\Git\SkipMarkers;

/**
 * `push` webhook → {@see GitPushReceived}, unless the ref was deleted, the
 * head commit carries a skip marker (docs/06 §2), or polling already saw this
 * head.
 */
final readonly class HandlePushEvent
{
    /** GitHub includes at most this many commits in a push payload. */
    private const int MAX_PAYLOAD_COMMITS = 20;

    public function __construct(
        private SyncInstallationRepositories $syncRepositories,
        private RecordRefHead $recordRefHead,
    ) {}

    /**
     * @return bool whether an event was emitted
     */
    public function handle(GitHubInstallation $installation, JsonObject $payload): bool
    {
        $repository = RepositoryInfo::fromJson($payload->object('repository'));
        $this->syncRepositories->handle($installation, [$repository]);

        $ref = $payload->string('ref');
        $after = $payload->string('after');

        if (! GitRef::isValid($ref)) {
            return false;
        }

        if ($payload->bool('deleted') || $after === GitRef::ZERO_SHA || ! GitRef::isSha($after)) {
            $this->recordRefHead->handle($installation, $repository->id, $ref, null);

            return false;
        }

        $headCommit = $this->headCommit($payload, $after);
        $update = $this->recordRefHead->handle($installation, $repository->id, $ref, $after);

        if (! $update->changed || SkipMarkers::matches($headCommit->message)) {
            return false;
        }

        event(new GitPushReceived($installation, $repository->id, $ref, $payload->string('before'), $after, $headCommit, $this->changedFiles($payload), $payload->bool('forced'), GitEventSource::Webhook));

        return true;
    }

    private function headCommit(JsonObject $payload, string $after): CommitData
    {
        $commit = $payload->objectOrNull('head_commit');
        $author = $commit?->objectOrNull('author');
        $login = $author?->stringOrNull('username');
        // The push payload's commit author has no avatar; the sender does,
        // and usually is that author.
        $sender = $payload->objectOrNull('sender');
        $senderAvatar = $login !== null && strcasecmp($sender?->stringOrNull('login') ?? '', $login) === 0 ? $sender?->stringOrNull('avatar_url') : null;

        return new CommitData(
            sha: $commit?->stringOrNull('id') ?? $after,
            message: $commit?->stringOrNull('message') ?? '',
            authorName: $author?->stringOrNull('name'),
            authorEmail: $author?->stringOrNull('email'),
            authorLogin: $login,
            authorAvatarUrl: GitHubAvatar::url($senderAvatar, $login),
        );
    }

    /**
     * Union of files touched by the pushed commits, or null when the payload
     * may be truncated and the list would be incomplete.
     *
     * @return list<string>|null
     */
    private function changedFiles(JsonObject $payload): ?array
    {
        $commits = $payload->objects('commits');

        if (count($commits) >= self::MAX_PAYLOAD_COMMITS) {
            return null;
        }

        $files = [];

        foreach ($commits as $commit) {
            foreach (['added', 'modified', 'removed'] as $key) {
                foreach ($commit->strings($key) as $file) {
                    $files[$file] = true;
                }
            }
        }

        $files = array_map(strval(...), array_keys($files));
        sort($files);

        return $files;
    }
}
