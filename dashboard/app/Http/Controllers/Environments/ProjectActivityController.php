<?php

declare(strict_types=1);

namespace App\Http\Controllers\Environments;

use App\Data\Activity\ActivityFiltersData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectScope;
use App\Models\Project;
use App\Queries\TeamActivity;
use Illuminate\Http\Request;
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
    public function __invoke(Request $request, Project $project, TeamActivity $activity): Response
    {
        Gate::authorize('view', $project);
        $request->validate(['kind' => ['nullable', 'string', 'in:deploys,promotions,changes']]);
        $kind = $request->string('kind')->value();
        $filters = new ActivityFiltersData($project->public_id, $kind === '' ? null : $kind);

        return Inertia::render('projects/activity', [
            ...ProjectScope::shellProps($project, null),
            'entries' => $activity->get($project->team_id, $filters),
            'filters' => ['project' => $project->public_id, 'kind' => $filters->kind],
        ]);
    }
}
