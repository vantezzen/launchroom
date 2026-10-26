<?php

declare(strict_types=1);

namespace App\Models\Git;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Last known head of a ref, shared by webhooks and polling so a push is
 * emitted once however it is observed; also stores the polling ETag.
 *
 * @property int $id
 * @property int $github_installation_id
 * @property int $github_repo_id
 * @property string $ref
 * @property string|null $head_sha
 * @property string|null $etag
 * @property CarbonImmutable|null $last_polled_at
 * @property CarbonImmutable|null $last_changed_at
 * @property CarbonImmutable|null $awaiting_webhook_since polling saw a new head that no webhook delivered yet
 * @property-read GitHubInstallation $installation
 */
#[Fillable(['github_installation_id', 'github_repo_id', 'ref', 'head_sha', 'etag', 'last_polled_at', 'last_changed_at', 'awaiting_webhook_since'])]
#[Table(name: 'github_ref_heads')]
final class GitHubRefHead extends Model
{
    /**
     * @return BelongsTo<GitHubInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(GitHubInstallation::class, 'github_installation_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_repo_id' => 'integer',
            'last_polled_at' => 'datetime',
            'last_changed_at' => 'datetime',
            'awaiting_webhook_since' => 'datetime',
        ];
    }
}
