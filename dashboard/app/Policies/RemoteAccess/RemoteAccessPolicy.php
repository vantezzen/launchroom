<?php

declare(strict_types=1);

namespace App\Policies\RemoteAccess;

use App\Models\RemoteAccess\RemoteAccess;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTaskBackedAccess;

/**
 * Temporary remote access (docs/09 R39): the same permission as the
 * matching session tab (`containers.exec` for SSH and volumes,
 * `databases.query` for databases).
 */
final class RemoteAccessPolicy
{
    use AuthorizesTaskBackedAccess;

    /**
     * See the password and connection details.
     */
    public function useCredentials(User $user, RemoteAccess $access): bool
    {
        return $this->opener($user, $access);
    }
}
