<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Data\Analytics\TeamAnalyticsData;
use App\Data\Analytics\TrafficReadinessData;
use App\Data\Api\AnalyticsQuery;
use App\Enums\EnvironmentKind;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Queries\TeamAnalytics;
use App\Queries\TrafficReadiness;
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
    public function show(#[CurrentTeam] Team $team, TrafficReadiness $readiness): Response
    {
        Gate::authorize('viewAny', [Project::class, $team]);

        return Inertia::render('analytics', [
            'team' => ['name' => $team->name],
            // Why there would be no requests (D109), across the production environments.
            'readiness' => fn (): TrafficReadinessData => $readiness->in(Environment::query()
                ->where('kind', EnvironmentKind::Production)
                ->whereIn('project_id', Project::query()->select('id')->where('team_id', $team->id))
                ->with('project')
                ->get()
                ->all()),
        ]);
    }

    public function data(AnalyticsQuery $query, TeamAnalytics $analytics, #[CurrentTeam] Team $team): TeamAnalyticsData
    {
        Gate::authorize('viewAny', [Project::class, $team]);

        return $analytics->handle($team, $query->range);
    }
}
