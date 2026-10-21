<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

final class ServerPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::View);
    }

    public function view(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team_id, Permission::View);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, Permission::ManageServers);
    }

    public function approve(User $user, Server $server): bool
    {
        return $server->status === ServerStatus::PendingApproval
            && $user->hasTeamPermission($server->team_id, Permission::ManageServers);
    }

    /**
     * Confirm the deletion of a retired volume whose service had backups (docs/05 §1.1).
     */
    public function confirmVolumeDeletion(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team_id, Permission::ManageServers);
    }

    /**
     * Placement settings: labels, "allow previews", "allow production".
     */
    public function update(User $user, Server $server): bool
    {
        return $server->status !== ServerStatus::Revoked
            && $user->hasTeamPermission($server->team_id, Permission::ManageServers);
    }

    /**
     * Roles after enrollment (edge, runtime, build; docs/02 §2.2). A
     * sensitive mutation: the route asks for the password again.
     */
    public function updateRoles(User $user, Server $server): bool
    {
        return $server->status !== ServerStatus::Revoked
            && $user->hasTeamPermission($server->team_id, Permission::ManageServers);
    }

    /**
     * "Replace server" (docs/14 §5.3): an unreachable or offline server's
     * role moves to another server, its data is restored there, and it is
     * revoked. Viewing the wizard (and its progress) only needs ManageServers.
     */
    public function replace(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team_id, Permission::ManageServers);
    }

    /**
     * "Move to another server" (docs/14 §13.2): everything on an online
     * server moves to another one, which then takes its place. Viewing the
     * wizard and its progress needs ManageServers; starting it a recent
     * password too (the route).
     */
    public function move(User $user, Server $server): bool
    {
        return $user->hasTeamPermission($server->team_id, Permission::ManageServers);
    }

    public function revoke(User $user, Server $server): bool
    {
        return $server->status !== ServerStatus::Revoked
            && $user->hasTeamPermission($server->team_id, Permission::ManageServers);
    }
}
