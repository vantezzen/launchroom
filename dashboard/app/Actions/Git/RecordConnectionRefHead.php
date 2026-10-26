<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\RefHeadUpdate;
use App\Models\Git\GitConnection;
use App\Models\Git\GitRefHead;
use Illuminate\Support\Facades\DB;

/**
 * {@see RecordRefHead} for GitLab/Gitea/plain git connections (docs/19 §3):
 * under a row lock, so one push is emitted once whether the webhook or
 * polling saw it first. A new head that polling sees first waits for its
 * webhook (docs/06 §2.2), unless a webhook asked for the poll.
 */
final class RecordConnectionRefHead
{
    /**
     * @param  string|null  $sha  null when the ref was deleted
     * @param  bool  $webhook  a webhook delivered this head (or asked for the poll that found it)
     */
    public function handle(GitConnection $connection, int $repositoryId, string $ref, ?string $sha, bool $polled = false, bool $webhook = false): RefHeadUpdate
    {
        $webhook = $webhook || ! $polled;

        return DB::transaction(function () use ($connection, $repositoryId, $ref, $sha, $polled, $webhook): RefHeadUpdate {
            $keys = ['git_connection_id' => $connection->id, 'external_id' => $repositoryId, 'ref' => $ref];

            GitRefHead::query()->insertOrIgnore([...$keys, 'created_at' => now(), 'updated_at' => now()]);

            $head = GitRefHead::query()->where($keys)->lockForUpdate()->sole();
            $previous = $head->head_sha;
            $changed = $previous !== $sha;
            $head->head_sha = $sha;

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

            return new RefHeadUpdate($previous, $changed);
        });
    }
}
