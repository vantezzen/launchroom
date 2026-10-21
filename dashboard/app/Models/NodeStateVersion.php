<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One rendered NodeState (docs/07). `document` holds the exact bytes served
 * to the daemon; the response signature covers them.
 *
 * @property int $id
 * @property int $server_id
 * @property int $version
 * @property string $document
 * @property string $document_hash
 * @property Carbon $created_at
 */
#[Fillable(['server_id', 'version', 'document', 'document_hash', 'created_at'])]
final class NodeStateVersion extends Model
{
    public const ?string UPDATED_AT = null;

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
