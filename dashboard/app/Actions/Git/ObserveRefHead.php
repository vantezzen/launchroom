<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\CommitData;
use App\Enums\Git\GitEventSource;
use App\Events\Git\GitPushReceived;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRefHead;
use App\Models\Git\GitRefHead;
use App\Support\Git\GitRef;
use App\Support\Git\SkipMarkers;
use Illuminate\Support\Facades\DB;

/**
 * One observation of a ref's head, from a webhook or polling (docs/06 §2,
 * docs/19 §3), for every provider. The head is recorded under a row lock, so
 * one push is emitted once whichever channel sees it first, then a changed
 * head without a skip marker emits {@see GitPushReceived}.
 *
 * Polling only reports what it saw: its first observation of a ref is a
 * baseline, and a new head it sees first waits for its webhook
 * (`awaiting_webhook_since`, docs/06 §2.2) unless a webhook asked for the
 * poll. Any webhook for the ref ends the wait.
 */
final class ObserveRefHead
{
    /**
     * @param  CommitData|null  $head  null when the ref was deleted
     * @param  string|null  $before  the push's previous head (webhooks); polling uses the recorded one
     * @param  list<string>|null  $changedFiles  null when unknown
     * @param  string|null  $etag  GitHub polling only; null keeps the stored one
     * @param  bool  $webhookAsked  a webhook asked for this poll (docs/19 §4.2)
     * @return bool whether an event was emitted
     */
    public function handle(
        GitHubInstallation|GitConnection $account,
        int $repositoryId,
        string $ref,
        ?CommitData $head,
        GitEventSource $source,
        ?string $before = null,
        ?array $changedFiles = null,
        bool $forced = false,
        ?string $etag = null,
        bool $webhookAsked = false,
    ): bool {
        $polled = $source === GitEventSource::Polling;
        $previous = $this->record($account, $repositoryId, $ref, $head?->sha, $polled, $webhookAsked || ! $polled, $etag);

        if (! $head instanceof CommitData || $previous === $head->sha || ($polled && $previous === null) || SkipMarkers::matches($head->message)) {
            return false;
        }

        $before = $polled ? $previous : $before;

        event(new GitPushReceived($account, $repositoryId, $ref, $before !== null && GitRef::isSha($before) ? $before : GitRef::ZERO_SHA, $head->sha, $head, $changedFiles, $forced, $source));

        return true;
    }

    /**
     * @return string|null the head recorded before this observation
     */
    private function record(GitHubInstallation|GitConnection $account, int $repositoryId, string $ref, ?string $sha, bool $polled, bool $webhook, ?string $etag): ?string
    {
        return DB::transaction(function () use ($account, $repositoryId, $ref, $sha, $polled, $webhook, $etag): ?string {
            [$model, $keys] = $account instanceof GitHubInstallation
                ? [GitHubRefHead::class, ['github_installation_id' => $account->id, 'github_repo_id' => $repositoryId, 'ref' => $ref]]
                : [GitRefHead::class, ['git_connection_id' => $account->id, 'external_id' => $repositoryId, 'ref' => $ref]];

            $model::query()->insertOrIgnore([...$keys, 'created_at' => now(), 'updated_at' => now()]);

            $head = $model::query()->where($keys)->lockForUpdate()->sole();
            $previous = $head->head_sha;
            $changed = $previous !== $sha;
            $head->head_sha = $sha;

            if ($etag !== null && $head instanceof GitHubRefHead) {
                $head->etag = $etag;
            }

            if ($polled) {
                $head->last_polled_at = now();
            }

            if ($changed) {
                $head->last_changed_at = now();
            }

            if ($webhook) {
                $head->awaiting_webhook_since = null;
            } elseif ($changed && $previous !== null && $sha !== null) {
                $head->awaiting_webhook_since ??= now()->toImmutable();
            }

            $head->save();

            return $previous;
        });
    }
}
