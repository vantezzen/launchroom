<?php

declare(strict_types=1);

namespace App\Policies\Alerts;

use App\Enums\Permission;
use App\Models\Alerts\Alert;
use App\Models\Team;
use App\Models\User;

/**
 * Alerts are read-only history for every member of the team (docs/15 §3.7).
 */
final class AlertPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::View);
    }

    public function view(User $user, Alert $alert): bool
    {
        return $user->hasTeamPermission($alert->team_id, Permission::View);
    }
}
