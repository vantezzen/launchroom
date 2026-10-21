<?php

declare(strict_types=1);

namespace App\Data\Teams;

use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Models\Membership;
use Spatie\LaravelData\Data;

final class MemberData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public TeamRole $role,
        public bool $isCurrentUser,
        public bool $canEdit,
        public bool $canRemove,
        /** @var list<Permission> individually granted Console/Data access (docs/09 R33) */
        public array $grants = [],
        /** @var list<Permission> grants the viewer may switch for this member */
        public array $grantable = [],
    ) {}

    /**
     * @param  list<Permission>  $grantable
     */
    public static function fromMembership(Membership $membership, bool $isCurrentUser, bool $canEdit, bool $canRemove, array $grantable = []): self
    {
        return new self(
            $membership->public_id,
            $membership->user->name,
            $membership->user->email,
            $membership->role,
            $isCurrentUser,
            $canEdit,
            $canRemove,
            $membership->grantedPermissions(),
            $grantable,
        );
    }
}
