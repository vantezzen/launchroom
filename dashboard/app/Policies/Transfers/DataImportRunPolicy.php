<?php

declare(strict_types=1);

namespace App\Policies\Transfers;

use App\Enums\Permission;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataImportRun;
use App\Models\User;

/**
 * An import writes a database or volume wholesale, the same power as a
 * restore in place (docs/14 §12.5): ManageBackups. Members who can see the
 * service see its imports.
 */
final class DataImportRunPolicy
{
    public function view(User $user, DataImportRun $run): bool
    {
        return $user->hasTeamPermission($run->team_id, Permission::View);
    }

    public function create(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::ManageBackups);
    }
}
