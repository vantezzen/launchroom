<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServerEnrollment;
use App\Models\User;

final class ServerEnrollmentPolicy
{
    public function view(User $user, ServerEnrollment $enrollment): bool
    {
        return $user->hasTeamPermission($enrollment->team_id, Permission::ManageServers);
    }

    /**
     * Remove a server that never connected (the join token is deleted).
     * Used enrolments belong to a server, which is revoked instead.
     */
    public function delete(User $user, ServerEnrollment $enrollment): bool
    {
        return $enrollment->used_at === null
            && ! $enrollment->is_control_plane
            && $user->hasTeamPermission($enrollment->team_id, Permission::ManageServers);
    }
}
