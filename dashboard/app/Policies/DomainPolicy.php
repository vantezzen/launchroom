<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\DomainKind;
use App\Enums\Permission;
use App\Models\Domain;
use App\Models\ServiceInstance;
use App\Models\User;

final class DomainPolicy
{
    public function view(User $user, Domain $domain): bool
    {
        return $user->hasTeamPermission($domain->team_id, Permission::View);
    }

    public function create(User $user, ServiceInstance $instance): bool
    {
        return $user->hasTeamPermission($instance->team_id, Permission::ManageProjects);
    }

    /**
     * www redirect and DNS re-checks: custom domains only.
     */
    public function update(User $user, Domain $domain): bool
    {
        return $domain->kind === DomainKind::Custom
            && $user->hasTeamPermission($domain->team_id, Permission::ManageProjects);
    }

    public function delete(User $user, Domain $domain): bool
    {
        return $domain->kind !== DomainKind::Deployment
            && $user->hasTeamPermission($domain->team_id, Permission::ManageProjects);
    }
}
