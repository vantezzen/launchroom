<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateProject;
use App\Data\Projects\CreateProjectData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Project;
use App\Models\User;
use App\Queries\ProjectSummaries;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The team's projects list, creating a project, and `/p/{project}`.
 */
final class ProjectController extends Controller
{
    public function index(#[CurrentUser] User $user, ProjectSummaries $summaries): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [Project::class, $team]);

        return Inertia::render('projects/index', [
            'projects' => $summaries->for($team),
            'canCreate' => Gate::allows('create', [Project::class, $team]),
        ]);
    }

    public function store(Request $request, CreateProjectData $data, #[CurrentUser] User $user, CreateProject $createProject): RedirectResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [Project::class, $team]);

        $project = $createProject->handle($team, $data, $this->auditActor($request, $user));

        return to_route('projects.show', $project);
    }

    /**
     * The project's home: the production canvas.
     */
    public function show(Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $production = $project->production()->firstOrFail();

        return to_route('projects.canvas', [$project, $production]);
    }
}
