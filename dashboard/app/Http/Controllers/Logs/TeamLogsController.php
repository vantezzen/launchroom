<?php

declare(strict_types=1);

namespace App\Http\Controllers\Logs;

use App\Data\Logs\LogExplorerPageData;
use App\Data\Logs\LogQueryData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\User;
use App\Queries\LogSearch;
use App\Support\Logs\LogScope;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/logs`: search the logs of every project of the current team (docs/08
 * §12), for owners and admins (`searchLogs` on the team). The same explorer
 * as a project's Logs tab, with a project column and `project:` filters;
 * no live tails (they belong to one environment).
 */
final class TeamLogsController extends Controller
{
    public function show(#[CurrentUser] User $user): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('searchLogs', $team);

        return Inertia::render('logs', [
            'team' => ['name' => $team->name],
        ]);
    }

    public function entries(LogQueryData $query, #[CurrentUser] User $user, LogSearch $logs): LogExplorerPageData
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('searchLogs', $team);

        return $logs->page(LogScope::team($team), $query);
    }
}
