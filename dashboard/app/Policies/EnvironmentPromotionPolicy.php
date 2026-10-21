<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\EnvironmentPromotion;
use App\Models\User;

final class EnvironmentPromotionPolicy
{
    public function view(User $user, EnvironmentPromotion $promotion): bool
    {
        return $user->hasTeamPermission($promotion->team_id, Permission::View);
    }
}
