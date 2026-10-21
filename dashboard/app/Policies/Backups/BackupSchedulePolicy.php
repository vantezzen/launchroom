<?php

declare(strict_types=1);

namespace App\Policies\Backups;

use App\Enums\Permission;
use App\Models\Backups\BackupSchedule;
use App\Models\User;

final class BackupSchedulePolicy
{
    public function view(User $user, BackupSchedule $schedule): bool
    {
        return $schedule->team_id === null
            ? $user->is_instance_owner
            : $user->hasTeamPermission($schedule->team_id, Permission::View);
    }

    public function update(User $user, BackupSchedule $schedule): bool
    {
        return $schedule->team_id === null
            ? $user->is_instance_owner
            : $user->hasTeamPermission($schedule->team_id, Permission::ManageBackups);
    }
}
