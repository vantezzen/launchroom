<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\CommitData;
use App\Enums\Git\GitEventSource;
use App\Enums\Git\GitProviderKind;
use App\Enums\Git\WebhookDeliveryStatus;
use App\Events\Git\GitPushReceived;
use App\Events\Git\PullRequestEventReceived;
use App\Models\Git\GitConnection;
use App\Models\Git\GitRepository;
use App\Models\Git\WebhookDelivery;
use App\Services\Git\Providers\ApiGitProvider;
use App\Services\Git\Providers\GiteaProvider;
use App\Services\Git\Providers\GitLabProvider;
use App\Services\GitHub\JsonObject;
use App\Support\Git\GitRef;
use App\Support\Git\ProviderAvatar;
use App\Support\Git\SkipMarkers;

/**
 * Normalizes a verified GitLab or Gitea/Forgejo delivery (docs/19 §5) into
 * the neutral {@see GitPushReceived} / {@see PullRequestEventReceived}.
 * Everything is scoped to the delivery's connection and through it to one
 * team (docs/09 R18): the payload's repository id is only ever matched
 * within that connection.
 */
final readonly class ProcessConnectionWebhook
{
    /** Push payloads list at most this many commits (GitLab 20, Gitea 20 by default). */
    private const int MAX_PAYLOAD_COMMITS = 20;

    private const array PERMISSION_CHECK_ACTIONS = ['opened', 'reopened', 'synchronize'];

    public function __construct(
        private GitLabProvider $gitlab,
        private GiteaProvider $gitea,
        private RecordConnectionRefHead $recordRefHead,
    ) {}

    public function handle(WebhookDelivery $delivery, string $rawBody): WebhookDeliveryStatus
    {
        $connection = $delivery->gitConnection;

        if (! $connection instanceof GitConnection || $connection->provider === GitProviderKind::Git) {
            return WebhookDeliveryStatus::Ignored;
        }

        $payload = JsonObject::decode($rawBody);
        $gitlab = $connection->provider === GitProviderKind::GitLab;

        $emitted = match ($delivery->event) {
            'push', 'tag_push' => $gitlab ? $this->gitLabPush($connection, $payload) : $this->giteaPush($connection, $payload),
            'merge_request' => $gitlab && $this->gitLabMergeRequest($connection, $payload),
            'pull_request' => ! $gitlab && $this->giteaPullRequest($connection, $payload),
            default => false,
        };

        return $emitted ? WebhookDeliveryStatus::Processed : WebhookDeliveryStatus::Ignored;
    }

    private function gitLabPush(GitConnection $connection, JsonObject $payload): bool
    {
        $project = $payload->object('project');
        $repository = $this->remember($this->gitlab, $connection, $project->int('id'), $project->string('path_with_namespace'), $project->stringOrNull('default_branch'), $project->intOrNull('visibility_level') !== 20);
        $commits = $payload->objects('commits');
        $after = $payload->string('after');
        $head = null;

        foreach ($commits as $commit) {
            if ($commit->stringOrNull('id') === $after) {
                $head = $commit;
            }
        }

        $author = $head?->objectOrNull('author');
        $login = $payload->stringOrNull('user_username');

        return $this->push($connection, $repository, $payload->string('ref'), $payload->string('before'), $after, new CommitData(
            sha: $after,
            message: $head?->stringOrNull('message') ?? '',
            authorName: $author?->stringOrNull('name') ?? $payload->stringOrNull('user_name'),
            authorEmail: $author?->stringOrNull('email'),
            authorLogin: $login,
            authorAvatarUrl: ProviderAvatar::url($payload->stringOrNull('user_avatar'), $connection->origin()),
        ), ($payload->intOrNull('total_commits_count') ?? count($commits)) > self::MAX_PAYLOAD_COMMITS ? null : $this->files($commits), false);
    }

    private function giteaPush(GitConnection $connection, JsonObject $payload): bool
    {
        $source = $payload->object('repository');
        $repository = $this->remember($this->gitea, $connection, $source->int('id'), $source->string('full_name'), $source->stringOrNull('default_branch'), $source->bool('private'));
        $commits = $payload->objects('commits');
        $after = $payload->string('after');
        $head = $payload->objectOrNull('head_commit');

        foreach ($commits as $commit) {
            if ($head === null && $commit->stringOrNull('id') === $after) {
                $head = $commit;
            }
        }

        $author = $head?->objectOrNull('author');
        $sender = $payload->objectOrNull('sender');

        return $this->push($connection, $repository, $payload->string('ref'), $payload->stringOrNull('before') ?? GitRef::ZERO_SHA, $after, new CommitData(
            sha: $after,
            message: $head?->stringOrNull('message') ?? '',
            authorName: $author?->stringOrNull('name'),
            authorEmail: $author?->stringOrNull('email'),
            authorLogin: $author?->stringOrNull('username') ?? $sender?->stringOrNull('login'),
            authorAvatarUrl: ProviderAvatar::url($sender?->stringOrNull('avatar_url'), $connection->origin()),
        ), ($payload->intOrNull('total_commits') ?? count($commits)) > self::MAX_PAYLOAD_COMMITS || count($commits) >= self::MAX_PAYLOAD_COMMITS ? null : $this->files($commits), false);
    }

    /**
     * @param  list<string>|null  $files
     */
    private function push(GitConnection $connection, GitRepository $repository, string $ref, string $before, string $after, CommitData $headCommit, ?array $files, bool $forced): bool
    {
        if (! GitRef::isValid($ref)) {
            return false;
        }

        if ($after === GitRef::ZERO_SHA || ! GitRef::isSha($after)) {
            $this->recordRefHead->handle($connection, $repository->external_id, $ref, null);

            return false;
        }

        $update = $this->recordRefHead->handle($connection, $repository->external_id, $ref, $after);

        if (! $update->changed || SkipMarkers::matches($headCommit->message)) {
            return false;
        }

        event(new GitPushReceived($connection, $repository->external_id, $ref, GitRef::isSha($before) ? $before : GitRef::ZERO_SHA, $after, $headCommit, $files, $forced, GitEventSource::Webhook));

        return true;
    }

    private function gitLabMergeRequest(GitConnection $connection, JsonObject $payload): bool
    {
        $project = $payload->object('project');
        $repository = $this->remember($this->gitlab, $connection, $project->int('id'), $project->string('path_with_namespace'), $project->stringOrNull('default_branch'), $project->intOrNull('visibility_level') !== 20);
        $mr = $payload->object('object_attributes');
        $targetId = $mr->int('target_project_id');
        $sourceId = $mr->intOrNull('source_project_id');
        $headSha = $mr->objectOrNull('last_commit')?->stringOrNull('id') ?? '';

        if ($targetId !== $repository->external_id || ! GitRef::isSha($headSha)) {
            return false;
        }

        $state = $mr->stringOrNull('state');
        $action = match ($mr->stringOrNull('action')) {
            'open' => 'opened',
            'reopen' => 'reopened',
            'update' => $mr->stringOrNull('oldrev') !== null ? 'synchronize' : 'edited',
            'close', 'merge' => 'closed',
            default => null,
        };

        if ($action === null) {
            return false;
        }

        $user = $payload->objectOrNull('user');
        $labels = array_map(static fn (JsonObject $label): string => $label->string('title'), $payload->objects('labels'));
        $isFork = $sourceId !== $targetId;

        // Approval by label (docs/09 R20): `preview` newly applied by someone with write access.
        $label = null;
        $changes = $payload->objectOrNull('changes')?->objectOrNull('labels');

        if ($action === 'edited' && $changes instanceof JsonObject) {
            $before = array_map(static fn (JsonObject $l): string => $l->string('title'), $changes->objects('previous'));
            $after = array_map(static fn (JsonObject $l): string => $l->string('title'), $changes->objects('current'));

            if (in_array('preview', $after, true) && ! in_array('preview', $before, true)) {
                $action = 'labeled';
                $label = 'preview';
            }
        }

        $sender = $user?->stringOrNull('username');
        $authorId = (string) ($mr->intOrNull('author_id') ?? 0);

        event(new PullRequestEventReceived(
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
            $isFork && in_array($action, self::PERMISSION_CHECK_ACTIONS, true) ? $this->gitlab->authorPermission($connection, $repository, $authorId) : null,
            $mr->bool('draft') || $mr->bool('work_in_progress'),
            $state === 'merged' || $mr->stringOrNull('action') === 'merge',
            $labels,
            $label,
            $label !== null ? $sender : null,
            $isFork && $label !== null ? $this->gitlab->authorPermission($connection, $repository, (string) ($user?->intOrNull('id') ?? 0)) : null,
        ));

        return true;
    }

    private function giteaPullRequest(GitConnection $connection, JsonObject $payload): bool
    {
        $source = $payload->object('repository');
        $repository = $this->remember($this->gitea, $connection, $source->int('id'), $source->string('full_name'), $source->stringOrNull('default_branch'), $source->bool('private'));
        $pullRequest = $payload->object('pull_request');
        $head = $pullRequest->object('head');
        $base = $pullRequest->object('base');
        $baseRepoId = $base->objectOrNull('repo')?->intOrNull('id') ?? $repository->external_id;
        $headRepoId = $head->objectOrNull('repo')?->intOrNull('id');
        $headSha = $head->stringOrNull('sha') ?? '';

        if ($baseRepoId !== $repository->external_id || ! GitRef::isSha($headSha)) {
            return false;
        }

        $action = match ($payload->stringOrNull('action')) {
            'opened' => 'opened',
            'reopened' => 'reopened',
            'synchronized' => 'synchronize',
            'closed' => 'closed',
            'edited' => 'edited',
            'label_updated' => 'labeled',
            'label_cleared' => 'unlabeled',
            default => null,
        };

        if ($action === null) {
            return false;
        }

        $labels = array_map(static fn (JsonObject $label): string => $label->string('name'), $pullRequest->objects('labels'));
        $isFork = $headRepoId !== $baseRepoId;
        $author = $pullRequest->objectOrNull('user')?->stringOrNull('login') ?? 'unknown';
        $label = $action === 'labeled' && in_array('preview', $labels, true) ? 'preview' : null;
        $sender = $label !== null ? $payload->objectOrNull('sender')?->stringOrNull('login') : null;

        event(new PullRequestEventReceived(
            $connection,
            $repository->external_id,
            $action,
            $payload->intOrNull('number') ?? $pullRequest->int('number'),
            $pullRequest->stringOrNull('title') ?? '',
            $headSha,
            $head->string('ref'),
            $base->string('ref'),
            $headRepoId,
            $baseRepoId,
            $isFork,
            mb_substr($author, 0, 100),
            $isFork && in_array($action, self::PERMISSION_CHECK_ACTIONS, true) ? $this->gitea->authorPermission($connection, $repository, $author) : null,
            $pullRequest->bool('draft'),
            $pullRequest->bool('merged'),
            $labels,
            $label,
            $sender,
            $isFork && $sender !== null ? $this->gitea->authorPermission($connection, $repository, $sender) : null,
        ));

        return true;
    }

    /**
     * The repository row of this connection (created from the signed payload when new).
     */
    private function remember(ApiGitProvider $provider, GitConnection $connection, int $id, string $fullName, ?string $defaultBranch, bool $private): GitRepository
    {
        $existing = GitRepository::query()->where('git_connection_id', $connection->id)->where('external_id', $id)->first();

        if ($existing instanceof GitRepository) {
            $existing->setRelation('gitConnection', $connection);

            return $existing;
        }

        return $provider->remember($connection, [
            'external_id' => $id,
            'full_name' => $fullName,
            'default_branch' => $defaultBranch,
            'private' => $private,
            'archived' => false,
            'pushed_at' => now()->toImmutable(),
        ]);
    }

    /**
     * Union of files touched by the pushed commits.
     *
     * @param  list<JsonObject>  $commits
     * @return list<string>
     */
    private function files(array $commits): array
    {
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
