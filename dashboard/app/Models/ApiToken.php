<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiAbility;
use App\Enums\ApiTokenClient;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A personal access token bound to one team (docs/16 §1, docs/09 R11).
 * Only the SHA-256 of the secret is stored (`token`, hidden). Abilities are
 * {@see ApiAbility} values; `*` is never issued and never honoured.
 *
 * @property int $id
 * @property string $public_id
 * @property string $tokenable_type
 * @property int $tokenable_id
 * @property string $name
 * @property string $token
 * @property list<string> $abilities
 * @property ApiTokenClient $client
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property string|null $created_ip
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['name', 'token', 'abilities', 'expires_at', 'team_id', 'client', 'created_ip'])]
#[Hidden(['token', 'tokenable_type', 'tokenable_id'])]
#[Table(name: 'personal_access_tokens')]
final class ApiToken extends PersonalAccessToken
{
    use BelongsToTeam, HasPublicId;

    public static function publicIdPrefix(): string
    {
        return 'tok';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tokenable_id');
    }

    /**
     * Exact ability check; `*` grants nothing (docs/16 §1.2).
     */
    public function hasAbility(ApiAbility $ability): bool
    {
        return in_array($ability->value, $this->abilities, true);
    }

    /**
     * Expired, missing an expiry (fail closed) or bound to no team.
     */
    public function isUsable(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture() && $this->team_id > 0;
    }

    /**
     * Tokens of a user (owner of the token rows: tokenable = User).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function ownedBy(Builder $query, User $user): Builder
    {
        return $query->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'client' => ApiTokenClient::class,
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
