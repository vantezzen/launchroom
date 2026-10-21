<?php

declare(strict_types=1);

namespace App\Policies\Backups;

use App\Enums\Permission;
use App\Models\Backups\WalArchive;
use App\Models\User;

/**
 * Team members see it; owners and admins (ManageBackups) change it
 * (docs/14 §10). Starting one goes through BackupRunPolicy on the instance.
 */
final class WalArchivePolicy
{
    public function view(User $user, WalArchive $model): bool
    {
        return $user->hasTeamPermission($model->team_id, Permission::View);
    }

    public function update(User $user, WalArchive $model): bool
    {
        return $user->hasTeamPermission($model->team_id, Permission::ManageBackups);
    }
}
