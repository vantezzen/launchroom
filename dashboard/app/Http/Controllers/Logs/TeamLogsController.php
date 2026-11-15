<?php

declare(strict_types=1);

namespace App\Http\Controllers\Logs;

use App\Data\Logs\LogExplorerPageData;
use App\Data\Logs\LogQueryData;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Models\Team;
use App\Queries\LogSearch;
use App\Support\Logs\LogScope;
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
    public function show(#[CurrentTeam] Team $team): Response
    {
        Gate::authorize('searchLogs', $team);

        return Inertia::render('logs', [
            'team' => ['name' => $team->name],
        ]);
    }

    public function entries(LogQueryData $query, LogSearch $logs, #[CurrentTeam] Team $team): LogExplorerPageData
    {
        Gate::authorize('searchLogs', $team);

        return $logs->page(LogScope::team($team), $query);
    }
}
