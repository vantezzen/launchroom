<?php

declare(strict_types=1);

namespace App\Policies\Transfers;

use App\Enums\Permission;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataExportRun;
use App\Models\User;

/**
 * An export takes decrypted data off the platform (docs/14 §12.5): starting
 * one and making its download link need ManageBackups (plus a recent
 * password in the dashboard).
 */
final class DataExportRunPolicy
{
    public function view(User $user, DataExportRun $run): bool
    {
        return $user->hasTeamPermission($run->team_id, Permission::View);
    }

    public function create(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::ManageBackups);
    }

    public function download(User $user, DataExportRun $run): bool
    {
        return $user->hasTeamPermission($run->team_id, Permission::ManageBackups);
    }
}
