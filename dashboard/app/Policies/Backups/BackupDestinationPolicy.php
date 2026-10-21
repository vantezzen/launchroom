<?php

declare(strict_types=1);

namespace App\Policies\Backups;

use App\Enums\Permission;
use App\Models\Backups\BackupDestination;
use App\Models\Team;
use App\Models\User;

/**
 * Team destinations: members see them, owners/admins manage them
 * (Permission::ManageBackups). The instance (control-plane) destination and
 * the recovery kit belong to instance owners only (docs/14 §4).
 */
final class BackupDestinationPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::View);
    }

    public function view(User $user, BackupDestination $destination): bool
    {
        return $destination->team_id === null
            ? $user->is_instance_owner
            : $user->hasTeamPermission($destination->team_id, Permission::View);
    }

    /**
     * $team null: the instance destination.
     */
    public function create(User $user, ?Team $team = null): bool
    {
        return $team instanceof Team ? $user->hasTeamPermission($team, Permission::ManageBackups) : $user->is_instance_owner;
    }

    public function update(User $user, BackupDestination $destination): bool
    {
        return $destination->team_id === null
            ? $user->is_instance_owner
            : $user->hasTeamPermission($destination->team_id, Permission::ManageBackups);
    }

    public function delete(User $user, BackupDestination $destination): bool
    {
        return $this->update($user, $destination);
    }

    /**
     * Control-plane backups, recovery passphrase and recovery kit.
     */
    public function manageInstance(User $user): bool
    {
        return $user->is_instance_owner;
    }
}
