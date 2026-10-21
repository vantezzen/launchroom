<?php

declare(strict_types=1);

namespace App\Policies\Sessions;

use App\Enums\Permission;
use App\Models\Sessions\DatabaseQuery;
use App\Models\User;

/**
 * Query history is personal: only its author, while they may query databases.
 */
final class DatabaseQueryPolicy
{
    public function view(User $user, DatabaseQuery $query): bool
    {
        return $query->user_id === $user->id && $user->hasTeamPermission($query->team_id, Permission::QueryDatabases);
    }
}
