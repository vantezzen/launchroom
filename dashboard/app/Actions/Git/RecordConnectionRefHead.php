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
 * polling saw it first.
 */
final class RecordConnectionRefHead
{
    /**
     * @param  string|null  $sha  null when the ref was deleted
     */
    public function handle(GitConnection $connection, int $repositoryId, string $ref, ?string $sha, bool $polled = false): RefHeadUpdate
    {
        return DB::transaction(function () use ($connection, $repositoryId, $ref, $sha, $polled): RefHeadUpdate {
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

            $head->save();

            return new RefHeadUpdate($previous, $changed);
        });
    }
}
