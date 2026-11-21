<?php

declare(strict_types=1);

namespace App\Policies\Transfers;

use App\Enums\EnvironmentKind;
use App\Enums\Permission;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataCopyRun;
use App\Models\User;

/**
 * Who may copy data between environments (D110, docs/14 §14.4, docs/09 R46).
 * Production's data is the team's most sensitive and its database the one
 * that matters most: copying out of it or into it needs ManageBackups
 * (owners and admins), the same as downloading or restoring it in place.
 * Between other environments developers may copy (ManageProjects and
 * Deploy): they could already remove and recreate those databases.
 */
final class DataCopyRunPolicy
{
    public function view(User $user, DataCopyRun $run): bool
    {
        return $user->hasTeamPermission($run->team_id, Permission::View);
    }

    /**
     * Whether the user may copy into $target at all (the sheet opens; the
     * source is checked by create()).
     */
    public function copyInto(User $user, ServiceInstance $target): bool
    {
        $target->loadMissing('environment');

        return $this->allowed($user, $target->team_id, $target->environment->kind === EnvironmentKind::Production);
    }

    public function create(User $user, ServiceInstance $source, ServiceInstance $target): bool
    {
        if ($source->team_id !== $target->team_id) {
            return false;
        }

        $source->loadMissing('environment');
        $target->loadMissing('environment');

        return $this->allowed($user, $target->team_id, $source->environment->kind === EnvironmentKind::Production || $target->environment->kind === EnvironmentKind::Production);
    }

    /** Whoever may start the copy may call it off before the import runs. */
    public function cancel(User $user, DataCopyRun $run): bool
    {
        return $this->create($user, $run->source, $run->target);
    }

    private function allowed(User $user, int $teamId, bool $production): bool
    {
        if ($production) {
            return $user->hasTeamPermission($teamId, Permission::ManageBackups);
        }

        return $user->hasTeamPermission($teamId, Permission::ManageProjects)
            && $user->hasTeamPermission($teamId, Permission::Deploy);
    }
}
