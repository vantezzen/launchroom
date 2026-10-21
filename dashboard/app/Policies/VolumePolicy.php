<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\Volume;

final class VolumePolicy
{
    public function view(User $user, Volume $volume): bool
    {
        return $user->hasTeamPermission($volume->team_id, Permission::View);
    }
}
