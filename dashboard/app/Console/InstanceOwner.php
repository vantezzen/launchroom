<?php

declare(strict_types=1);

namespace App\Console;

use App\Models\Team;
use App\Models\User;

/**
 * Who console commands act for when no team is passed: the first instance
 * owner and their current team.
 */
final class InstanceOwner
{
    public static function first(): ?User
    {
        return User::query()->where('is_instance_owner', true)->orderBy('id')->first();
    }

    public static function team(): ?Team
    {
        return self::first()?->currentTeam()->first();
    }

    /**
     * Dev commands only: the owner's current team, else the first team.
     */
    public static function teamOrFirst(): ?Team
    {
        return self::team() ?? Team::query()->orderBy('id')->first();
    }
}
