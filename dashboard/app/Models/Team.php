<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Teams\TeamSettingsData;
use App\Enums\TeamRole;
use App\Models\Concerns\HasPublicId;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property bool $personal
 * @property TeamSettingsData $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'personal', 'settings'])]
final class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'settings' => '{}',
    ];

    public static function publicIdPrefix(): string
    {
        return 'team';
    }

    /**
     * @return BelongsToMany<User, $this, Membership, 'membership'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
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
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * @return HasMany<ServerEnrollment, $this>
     */
    public function serverEnrollments(): HasMany
    {
        return $this->hasMany(ServerEnrollment::class);
    }

    public function ownerCount(): int
    {
        return $this->memberships()->where('role', TeamRole::Owner->value)->count();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal' => 'boolean',
            'settings' => TeamSettingsData::class,
        ];
    }
}
