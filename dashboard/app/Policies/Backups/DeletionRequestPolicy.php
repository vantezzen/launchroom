<?php

declare(strict_types=1);

namespace App\Policies\Backups;

use App\Enums\Permission;
use App\Models\Backups\DeletionRequest;
use App\Models\User;

/**
 * Deletions waiting for their final backup (docs/14 §9.13): members see
 * them; whoever may delete projects retries the final backup; only owners
 * and admins (ManageBackups, with a recent password on the route) delete
 * without one.
 */
final class DeletionRequestPolicy
{
    public function view(User $user, DeletionRequest $request): bool
    {
        return $user->hasTeamPermission($request->team_id, Permission::View);
    }

    public function retry(User $user, DeletionRequest $request): bool
    {
        return $user->hasTeamPermission($request->team_id, Permission::ManageProjects);
    }

    /**
     * "Delete without final backup", for an open request or upfront ($teamId).
     */
    public function skipFinalBackup(User $user, DeletionRequest|int $subject): bool
    {
        return $user->hasTeamPermission($subject instanceof DeletionRequest ? $subject->team_id : $subject, Permission::ManageBackups);
    }
}
