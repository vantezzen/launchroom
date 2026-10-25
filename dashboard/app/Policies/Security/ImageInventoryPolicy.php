<?php

declare(strict_types=1);

namespace App\Policies\Security;

use App\Enums\Permission;
use App\Models\Security\ImageInventory;
use App\Models\User;

/**
 * Inventories (docs/22 §3, §12): every member of the team sees them.
 */
final class ImageInventoryPolicy
{
    public function view(User $user, ImageInventory $inventory): bool
    {
        return $user->hasTeamPermission($inventory->team_id, Permission::View);
    }
}
