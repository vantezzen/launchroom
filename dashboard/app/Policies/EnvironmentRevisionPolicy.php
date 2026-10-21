<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\EnvironmentRevision;
use App\Models\User;

final class EnvironmentRevisionPolicy
{
    public function view(User $user, EnvironmentRevision $revision): bool
    {
        return $user->hasTeamPermission($revision->team_id, Permission::View);
    }
}
