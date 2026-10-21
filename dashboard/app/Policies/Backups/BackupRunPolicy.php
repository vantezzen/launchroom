<?php

declare(strict_types=1);

namespace App\Policies\Backups;

use App\Enums\Permission;
use App\Models\Backups\BackupRun;
use App\Models\ServiceInstance;
use App\Models\User;

/**
 * Seeing a service's backups needs View; taking one now needs Deploy
 * (it is as disruptive as a deploy, no more); backup settings need
 * ManageBackups.
 */
final class BackupRunPolicy
{
    public function viewAny(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::View);
    }

    public function view(User $user, BackupRun $run): bool
    {
        return $run->team_id === null
            ? $user->is_instance_owner
            : $user->hasTeamPermission($run->team_id, Permission::View);
    }

    public function create(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::Deploy);
    }

    /**
     * Per-volume backup settings (consistency): owners and admins.
     */
    public function configure(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::ManageBackups);
    }
}
