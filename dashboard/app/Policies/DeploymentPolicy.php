<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\DeploymentStatus;
use App\Enums\Permission;
use App\Models\Deployment;
use App\Models\User;

final class DeploymentPolicy
{
    public function view(User $user, Deployment $deployment): bool
    {
        return $user->hasTeamPermission($deployment->team_id, Permission::View);
    }

    public function rollback(User $user, Deployment $deployment): bool
    {
        return $deployment->status === DeploymentStatus::Superseded
            && $deployment->image !== null
            && $deployment->image_pruned_at === null
            && $user->hasTeamPermission($deployment->team_id, Permission::Deploy);
    }

    /**
     * Redeploy (same revision and commit) or cancel: the "Deploy" permission
     * (docs/09 R9).
     */
    public function redeploy(User $user, Deployment $deployment): bool
    {
        return $user->hasTeamPermission($deployment->team_id, Permission::Deploy);
    }

    /**
     * Route traffic to a staged deployment that is ready (docs/06 §6.5).
     */
    public function promote(User $user, Deployment $deployment): bool
    {
        return $deployment->status === DeploymentStatus::Ready
            && $user->hasTeamPermission($deployment->team_id, Permission::Deploy);
    }

    public function cancel(User $user, Deployment $deployment): bool
    {
        return $deployment->status->isCancellable()
            && $user->hasTeamPermission($deployment->team_id, Permission::Deploy);
    }
}
