<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServerRole;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A join token for one server (docs/03 §3, docs/09 R21): 256-bit, hashed,
 * single use, short-lived, bound to a team and roles.
 *
 * @property int $id
 * @property string $public_id
 * @property string $token_hash
 * @property Collection<int, ServerRole> $roles
 * @property string|null $name
 * @property bool $is_control_plane
 * @property int|null $created_by
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int|null $server_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server|null $server
 */
#[Fillable(['team_id', 'token_hash', 'roles', 'name', 'is_control_plane', 'created_by', 'expires_at'])]
#[Hidden(['token_hash'])]
final class ServerEnrollment extends Model
{
    use BelongsToTeam, HasPublicId;

    public static function publicIdPrefix(): string
    {
        return 'enr';
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

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
            'roles' => AsEnumCollection::of(ServerRole::class),
            'is_control_plane' => 'boolean',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
