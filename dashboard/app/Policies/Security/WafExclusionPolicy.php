<?php

declare(strict_types=1);

namespace App\Policies\Security;

use App\Enums\Permission;
use App\Models\Security\WafExclusion;
use App\Models\ServiceInstance;
use App\Models\User;

/**
 * Firewall settings and exceptions (docs/22 §6.4, §12): every member sees
 * them; changing the mode or paranoia and adding or removing exceptions
 * need `projects.manage`.
 */
final class WafExclusionPolicy
{
    public function viewAny(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::View);
    }

    public function create(User $user, ServiceInstance $instance): bool
    {
        return $instance->removed_at === null && $user->hasTeamPermission($instance->team_id, Permission::ManageProjects);
    }

    public function delete(User $user, WafExclusion $exclusion): bool
    {
        return $user->hasTeamPermission($exclusion->team_id, Permission::ManageProjects);
    }
}
