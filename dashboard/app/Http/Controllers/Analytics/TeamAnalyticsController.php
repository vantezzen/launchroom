<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Data\Analytics\TeamAnalyticsData;
use App\Data\Api\AnalyticsQuery;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Models\Project;
use App\Models\Team;
use App\Queries\TeamAnalytics;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/analytics` (sidebar; docs/15 §5.4): requests of every project of the
 * current team, per project over time, with the drill-down into a
 * project's Analytics tab. `GET /analytics/-/data?range=` returns the
 * numbers (JSON, refetched). Members who can see the team's projects can
 * see this.
 */
final class TeamAnalyticsController extends Controller
{
    public function show(#[CurrentTeam] Team $team): Response
    {
        Gate::authorize('viewAny', [Project::class, $team]);

        return Inertia::render('analytics', [
            'team' => ['name' => $team->name],
        ]);
    }

    public function data(AnalyticsQuery $query, TeamAnalytics $analytics, #[CurrentTeam] Team $team): TeamAnalyticsData
    {
        Gate::authorize('viewAny', [Project::class, $team]);

        return $analytics->handle($team, $query->range);
    }
}
