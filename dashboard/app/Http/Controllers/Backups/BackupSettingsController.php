<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backups;

use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Models\Backups\BackupDestination;
use App\Models\Team;
use App\Models\User;
use App\Queries\BackupSettingsPage;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Backups (docs/14 §3, docs/08 §2): the team's destinations and
 * database schedules; for instance owners also the control-plane
 * destination, its schedule and runs, and the recovery kit.
 */
final class BackupSettingsController extends Controller
{
    public function edit(#[CurrentUser] User $user, #[CurrentTeam] Team $team, BackupSettingsPage $page): Response
    {
        Gate::authorize('viewAny', [BackupDestination::class, $team]);

        return Inertia::render('settings/backups', $page->for($team, $user));
    }
}
