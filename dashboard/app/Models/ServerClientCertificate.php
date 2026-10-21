<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One client certificate issued to a server (docs/03 §4.3). Public data
 * only: the key never leaves the server.
 *
 * @property int $id
 * @property int $server_id
 * @property int $ca_id
 * @property string $fingerprint lowercase hex SHA-256 of the DER
 * @property string $serial hex
 * @property Carbon $not_before
 * @property Carbon $not_after
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property Carbon|null $last_presented_at
 * @property Carbon $created_at
 * @property-read Server $server
 */
#[Fillable(['server_id', 'ca_id', 'fingerprint', 'serial', 'not_before', 'not_after', 'revoked_at', 'revoked_reason', 'last_presented_at', 'created_at'])]
final class ServerClientCertificate extends Model
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
     * Not revoked and within its validity.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('not_before', '<=', now())->where('not_after', '>', now());
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->not_before->isPast() && $this->not_after->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'not_before' => 'datetime',
            'not_after' => 'datetime',
            'revoked_at' => 'datetime',
            'last_presented_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
