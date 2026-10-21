<?php

declare(strict_types=1);

namespace App\Policies\Transfers;

use App\Enums\Permission;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataTransfer;
use App\Models\User;

/**
 * Transfers carry whole databases and volumes (docs/14 §12.5): uploading,
 * resuming, completing, cancelling and downloading need ManageBackups
 * (owners and admins; tokens with `data:transfer`).
 */
final class DataTransferPolicy
{
    public function view(User $user, DataTransfer $transfer): bool
    {
        return $user->hasTeamPermission($transfer->team_id, Permission::ManageBackups);
    }

    public function update(User $user, DataTransfer $transfer): bool
    {
        return $user->hasTeamPermission($transfer->team_id, Permission::ManageBackups);
    }

    public function delete(User $user, DataTransfer $transfer): bool
    {
        return $user->hasTeamPermission($transfer->team_id, Permission::ManageBackups);
    }

    public function create(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::ManageBackups);
    }

    /**
     * Following a download link: still allowed to export, and the link's own user.
     */
    public function download(User $user, DataTransfer $transfer): bool
    {
        return $transfer->link_user_id === $user->id
            && $user->hasTeamPermission($transfer->team_id, Permission::ManageBackups);
    }
}
