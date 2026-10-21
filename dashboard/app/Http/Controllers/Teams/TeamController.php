<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateTeam;
use App\Data\Teams\CreateTeamData;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class TeamController extends Controller
{
    /**
     * "Create team" from the team switcher: the creator becomes its owner
     * (audited in CreateTeam) and switches to it right away.
     */
    public function store(Request $request, CreateTeamData $data, #[CurrentUser] User $user, CreateTeam $createTeam): RedirectResponse
    {
        Gate::authorize('create', Team::class);

        $team = $createTeam->handle($user, $data->name, false, $this->auditActor($request, $user), switchTo: true);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$team->name} is ready. Invite people under Settings → Team."]);

        return to_route('projects.index');
    }
}
