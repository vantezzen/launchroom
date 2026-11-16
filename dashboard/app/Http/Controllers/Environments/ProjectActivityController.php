<?php

declare(strict_types=1);

namespace App\Http\Controllers\Environments;

use App\Data\Activity\ActivityFiltersData;
use App\Data\Activity\ProjectActivityFilterData;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Queries\ProjectShell;
use App\Queries\TeamActivity;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/p/{project}/activity`: the activity feed filtered to one project
 * (deploys, rollbacks, promotions, environment copies, services added),
 * newest first, grouped by day on the page.
 */
final class ProjectActivityController extends Controller
{
    public function __invoke(Project $project, ProjectActivityFilterData $data, TeamActivity $activity): Response
    {
        Gate::authorize('view', $project);
        $filters = new ActivityFiltersData($project->public_id, $data->kind);

        return Inertia::render('projects/activity', [
            ...ProjectShell::props($project, null),
            'entries' => $activity->get($project->team_id, $filters),
            'filters' => ['project' => $project->public_id, 'kind' => $filters->kind, 'state' => $filters->state],
        ]);
    }
}
