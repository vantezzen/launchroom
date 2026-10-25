<?php

declare(strict_types=1);

namespace App\Policies\Security;

use App\Enums\Permission;
use App\Models\Security\SecurityInvestigation;
use App\Models\ServiceInstance;
use App\Models\User;

/**
 * Investigations (docs/22 §7, §12): every member reads reports; starting
 * one needs `projects.manage`.
 */
final class SecurityInvestigationPolicy
{
    public function view(User $user, SecurityInvestigation $investigation): bool
    {
        return $user->hasTeamPermission($investigation->team_id, Permission::View);
    }

    public function create(User $user, ServiceInstance $instance): bool
    {
        return $instance->removed_at === null && $user->hasTeamPermission($instance->team_id, Permission::ManageProjects);
    }
}
