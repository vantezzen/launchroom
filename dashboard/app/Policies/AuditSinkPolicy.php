<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Audit log destinations (docs/09 R34) are instance-wide: they receive
 * every team's entries, so only instance owners see and manage them. No
 * check depends on the sink itself (Laravel passes it; it is ignored).
 */
final class AuditSinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_instance_owner === true;
    }

    public function create(User $user): bool
    {
        return $user->is_instance_owner === true;
    }

    public function update(User $user): bool
    {
        return $user->is_instance_owner === true;
    }

    public function delete(User $user): bool
    {
        return $this->update($user);
    }

    public function test(User $user): bool
    {
        return $this->update($user);
    }
}
