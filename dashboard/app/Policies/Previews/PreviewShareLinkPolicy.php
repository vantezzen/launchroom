<?php

declare(strict_types=1);

namespace App\Policies\Previews;

use App\Enums\Permission;
use App\Models\Previews\PreviewShareLink;
use App\Models\User;

final class PreviewShareLinkPolicy
{
    public function delete(User $user, PreviewShareLink $link): bool
    {
        return $user->hasTeamPermission($link->team_id, Permission::Deploy);
    }
}
