<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\SwitchCurrentTeam;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class CurrentTeamController extends Controller
{
    public function update(Team $team, #[CurrentUser] User $user, SwitchCurrentTeam $switchCurrentTeam): RedirectResponse
    {
        Gate::authorize('switchTo', $team);

        $switchCurrentTeam->handle($user, $team);

        return to_route('dashboard');
    }
}
