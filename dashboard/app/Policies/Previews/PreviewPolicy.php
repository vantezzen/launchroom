<?php

declare(strict_types=1);

namespace App\Policies\Previews;

use App\Enums\Permission;
use App\Models\Previews\Preview;
use App\Models\User;

final class PreviewPolicy
{
    public function view(User $user, Preview $preview): bool
    {
        return $user->hasTeamPermission($preview->team_id, Permission::View);
    }

    /**
     * Approve a fork's preview (docs/09 R20): the same people who deploy.
     */
    public function approve(User $user, Preview $preview): bool
    {
        return $preview->is_fork && $user->hasTeamPermission($preview->team_id, Permission::Deploy);
    }

    /**
     * Create share links (they grant access without a launchroom login).
     */
    public function share(User $user, Preview $preview): bool
    {
        return $user->hasTeamPermission($preview->team_id, Permission::Deploy);
    }
}
