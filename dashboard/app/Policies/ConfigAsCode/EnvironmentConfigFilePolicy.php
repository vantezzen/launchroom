<?php

declare(strict_types=1);

namespace App\Policies\ConfigAsCode;

use App\Enums\Permission;
use App\Models\ConfigAsCode\EnvironmentConfigFile;
use App\Models\User;

final class EnvironmentConfigFilePolicy
{
    public function view(User $user, EnvironmentConfigFile $file): bool
    {
        return $user->hasTeamPermission($file->team_id, Permission::View);
    }
}
