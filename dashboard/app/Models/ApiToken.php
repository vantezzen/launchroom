<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiAbility;
use App\Enums\ApiTokenClient;
use App\Enums\Permission;
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
 * {@see ApiAbility} values; `*` is never issued and never honoured. A
 * `full_access` token has every ability, including ones added after it was
 * created (D113), still only what its holder's role allows; its stored list
 * is what that was at creation, for the record. `hint`
 * (`lr_pat_…a1b2`) is not secret: the prefix and the last four characters,
 * so a row can be matched to the string someone holds.
 *
 * @property int $id
 * @property string $public_id
 * @property string $tokenable_type
 * @property int $tokenable_id
 * @property string $name
 * @property string $token
 * @property list<string> $abilities
 * @property bool $full_access
 * @property ApiTokenClient $client
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property string|null $created_ip
 * @property string|null $hint
 * @property int|null $project_id
 * @property int|null $service_id
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Project|null $project
 * @property-read Service|null $service
 */
#[Fillable(['name', 'token', 'abilities', 'full_access', 'expires_at', 'team_id', 'client', 'created_ip', 'hint'])]
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Narrowed to one project or one service (docs/16 §1.4); enforced by
     * App\Http\Middleware\EnforceTokenScope.
     */
    public function isScoped(): bool
    {
        return $this->project_id !== null || $this->service_id !== null;
    }

    /**
     * Exact ability check; `*` grants nothing (docs/16 §1.2). A full-access
     * token has every ability its holder's role can use now (D113).
     */
    public function hasAbility(ApiAbility $ability): bool
    {
        if ($this->full_access) {
            return $this->holder()->roleAllows($this->team_id, $ability->grants() ?? Permission::View);
        }

        return in_array($ability->value, $this->abilities, true);
    }

    /**
     * Whether an ability of this token reaches $permission. Never one no
     * ability maps to (servers, members, team), full access or not; the
     * role is checked by the caller (User::hasTeamPermission()).
     */
    public function reaches(Permission $permission): bool
    {
        return ApiAbility::allows($this->full_access ? ApiAbility::values() : $this->abilities, $permission);
    }

    /**
     * The abilities this token has now, in the enum's order: the stored
     * list, or for full access every ability the holder's role can use.
     *
     * @return list<ApiAbility>
     */
    public function effectiveAbilities(): array
    {
        if ($this->full_access) {
            return array_values(array_filter(ApiAbility::cases(), $this->hasAbility(...)));
        }

        return array_values(array_filter(ApiAbility::cases(), fn (ApiAbility $ability): bool => in_array($ability->value, $this->abilities, true)));
    }

    /** The user (or robot) the token belongs to. */
    private function holder(): User
    {
        $tokenable = $this->relationLoaded('tokenable') ? $this->getRelation('tokenable') : null;

        return $tokenable instanceof User ? $tokenable : $this->user;
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
            'full_access' => 'boolean',
            'client' => ApiTokenClient::class,
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
