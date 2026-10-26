<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\RefHeadUpdate;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRefHead;
use Illuminate\Support\Facades\DB;

/**
 * Records the head of a ref, under a row lock, and says whether it changed.
 * Webhooks and polling both go through here, so one push is emitted once no
 * matter which channel sees it first. A new head that polling sees first
 * waits for its webhook (`awaiting_webhook_since`, docs/06 §2.2); any
 * webhook for the ref ends the wait.
 */
final class RecordRefHead
{
    /**
     * @param  string|null  $sha  null when the ref was deleted
     * @param  string|null  $etag  only from polling; null keeps the stored one
     */
    public function handle(GitHubInstallation $installation, int $repositoryId, string $ref, ?string $sha, ?string $etag = null, bool $polled = false): RefHeadUpdate
    {
        return DB::transaction(function () use ($installation, $repositoryId, $ref, $sha, $etag, $polled): RefHeadUpdate {
            $keys = ['github_installation_id' => $installation->id, 'github_repo_id' => $repositoryId, 'ref' => $ref];

            GitHubRefHead::query()->insertOrIgnore([...$keys, 'created_at' => now(), 'updated_at' => now()]);

            $head = GitHubRefHead::query()->where($keys)->lockForUpdate()->sole();
            $previous = $head->head_sha;
            $changed = $previous !== $sha;

            $head->head_sha = $sha;

            if ($etag !== null) {
                $head->etag = $etag;
            }

            if ($polled) {
                $head->last_polled_at = now();
            }

            if ($changed) {
                $head->last_changed_at = now();
            }

            if (! $polled) {
                $head->awaiting_webhook_since = null;
            } elseif ($changed && $previous !== null && $sha !== null) {
                $head->awaiting_webhook_since ??= now()->toImmutable();
            }

            $head->save();

            return new RefHeadUpdate($previous, $changed);
        });
    }
}
