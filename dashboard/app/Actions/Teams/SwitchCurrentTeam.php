<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Exceptions\DomainRuleViolation;
use App\Models\Team;
use App\Models\User;

final class SwitchCurrentTeam
{
    public function handle(User $user, Team $team): void
    {
        if (! $user->belongsToTeam($team)) {
            throw DomainRuleViolation::forbidden('You are not a member of this team.');
        }

        $user->forceFill(['current_team_id' => $team->id])->save();
    }
}
