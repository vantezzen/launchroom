<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiAbility;
use App\Enums\Permission;
use App\Enums\TeamRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property bool $is_instance_owner
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
final class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_instance_owner' => false,
    ];

    /**
     * Per-instance memo of team roles (team id => role|null); never persisted.
     *
     * @var array<int, TeamRole|null>
     */
    private array $teamRoles = [];

    /**
     * Per-instance memo of individually granted permissions (team id => list).
     *
     * @var array<int, list<Permission>>
     */
    private array $teamGrants = [];

    /**
     * @return BelongsToMany<Team, $this, Membership, 'membership'>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['id', 'public_id', 'role'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * Subquery of the ids of every team the user belongs to, for scoping
     * lookups of team-owned resources (docs/09 R7).
     *
     * @return Builder<Membership>
     */
    public function teamIdsQuery(): Builder
    {
        $query = Membership::query()->select('team_id')->where('user_id', $this->id);
        $token = $this->currentApiToken();

        // A token reaches its own team only (docs/16 §1.1): everything that
        // scopes through here (route bindings, lists) narrows with it.
        return $token instanceof ApiToken ? $query->where('team_id', $token->team_id) : $query;
    }

    /**
     * The API token this request authenticated with, if any (never set for
     * dashboard sessions).
     */
    public function currentApiToken(): ?ApiToken
    {
        $token = $this->currentAccessToken();

        return $token instanceof ApiToken ? $token : null;
    }

    public function roleIn(Team|int $team): ?TeamRole
    {
        $teamId = $team instanceof Team ? $team->id : $team;
        $token = $this->currentApiToken();

        if ($token instanceof ApiToken && $token->team_id !== $teamId) {
            return null;
        }

        if (! array_key_exists($teamId, $this->teamRoles)) {
            $membership = Membership::query()
                ->where('team_id', $teamId)
                ->where('user_id', $this->id)
                ->first(['role', 'permissions']);

            $this->teamRoles[$teamId] = $membership?->role;
            $this->teamGrants[$teamId] = $membership?->grantedPermissions() ?? [];
        }

        return $this->teamRoles[$teamId];
    }

    /**
     * Whether the member was granted $permission individually (docs/09 R33).
     */
    private function hasGrant(int $teamId, Permission $permission): bool
    {
        return $permission->isGrantable()
            && $this->roleIn($teamId) instanceof TeamRole
            && in_array($permission, $this->teamGrants[$teamId] ?? [], true);
    }

    public function belongsToTeam(Team|int $team): bool
    {
        return $this->roleIn($team) instanceof TeamRole;
    }

    /**
     * The role's permission, narrowed by the abilities of the API token the
     * request uses (docs/16 §1.2): every policy enforces token abilities
     * through here without knowing about tokens.
     */
    public function hasTeamPermission(Team|int $team, Permission $permission): bool
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        if (! ($this->roleIn($teamId)?->can($permission) ?? false) && ! $this->hasGrant($teamId, $permission)) {
            return false;
        }

        $token = $this->currentApiToken();

        return ! $token instanceof ApiToken || ApiAbility::allows($token->abilities, $permission);
    }

    /**
     * Forget memoized roles (after a membership change in the same request).
     */
    public function forgetTeamRoles(): void
    {
        $this->teamRoles = [];
        $this->teamGrants = [];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_instance_owner' => 'boolean',
        ];
    }
}
