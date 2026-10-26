<?php

declare(strict_types=1);

namespace App\Models\Git;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Last known head of a ref of a connection's repository (docs/19 §3), shared
 * by webhooks and polling so a push is emitted once.
 *
 * @property int $id
 * @property int $git_connection_id
 * @property int $external_id
 * @property string $ref
 * @property string|null $head_sha
 * @property CarbonImmutable|null $last_polled_at
 * @property CarbonImmutable|null $last_changed_at
 * @property CarbonImmutable|null $awaiting_webhook_since polling saw a new head that no webhook delivered yet
 * @property-read GitConnection $connection
 */
#[Fillable(['git_connection_id', 'external_id', 'ref', 'head_sha', 'last_polled_at', 'last_changed_at', 'awaiting_webhook_since'])]
#[Table(name: 'git_ref_heads')]
final class GitRefHead extends Model
{
    /**
     * @return BelongsTo<GitConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(GitConnection::class, 'git_connection_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'last_polled_at' => 'datetime',
            'last_changed_at' => 'datetime',
            'awaiting_webhook_since' => 'datetime',
        ];
    }
}
