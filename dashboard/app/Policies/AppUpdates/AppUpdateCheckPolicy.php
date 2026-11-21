<?php

declare(strict_types=1);

namespace App\Policies\AppUpdates;

use App\Enums\Permission;
use App\Models\AppUpdates\AppUpdateCheck;
use App\Models\User;

/**
 * A service's version check (D106) is visible to everyone in its team.
 */
final class AppUpdateCheckPolicy
{
    public function view(User $user, AppUpdateCheck $check): bool
    {
        return $user->hasTeamPermission($check->team_id, Permission::View);
    }
}
