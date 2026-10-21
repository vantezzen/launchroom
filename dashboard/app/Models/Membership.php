<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A user's membership of a team (`team_user`) with its role (docs/07).
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property TeamRole $role
 * @property list<string> $permissions granted on top of the role (Permission::grantable(), docs/09 R33)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['team_id', 'user_id', 'role'])]
#[Table(name: 'team_user')]
final class Membership extends Pivot
{
    use BelongsToTeam, HasPublicId;

    public $incrementing = true;

    /** @var array<string, mixed> */
    protected $attributes = [
        'permissions' => '[]',
    ];

    public static function publicIdPrefix(): string
    {
        return 'mem';
    }

    /**
     * The grantable permissions this member was granted (only those their
     * role accepts; a stale grant after a role change counts for nothing).
     *
     * @return list<Permission>
     */
    public function grantedPermissions(): array
    {
        $granted = [];

        foreach ($this->permissions as $value) {
            $permission = Permission::tryFrom($value);

            if ($permission instanceof Permission && $this->role->acceptsGrant($permission)) {
                $granted[] = $permission;
            }
        }

        return $granted;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'permissions' => 'array',
        ];
    }
}
