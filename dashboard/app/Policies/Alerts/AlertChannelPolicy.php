<?php

declare(strict_types=1);

namespace App\Policies\Alerts;

use App\Enums\Permission;
use App\Models\Alerts\AlertChannel;
use App\Models\Team;
use App\Models\User;

/**
 * Notification channels (docs/15 §3.7): every member sees them, owners and
 * admins (Permission::ManageAlerts) add, change, test and remove them.
 */
final class AlertChannelPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::View);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageAlerts);
    }

    public function update(User $user, AlertChannel $channel): bool
    {
        return $user->hasTeamPermission($channel->team_id, Permission::ManageAlerts);
    }

    public function delete(User $user, AlertChannel $channel): bool
    {
        return $this->update($user, $channel);
    }

    public function test(User $user, AlertChannel $channel): bool
    {
        return $this->update($user, $channel);
    }

    /**
     * Alert rules and thresholds of the team.
     */
    public function manageRules(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageAlerts);
    }
}
