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
use App\Queries\TeamAttention;
use App\Queries\TeamRecentDeployments;
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
    /** Rows of "Recent deployments" on the projects page. */
    private const int RECENT_DEPLOYMENTS = 3;

    /**
     * The team's home (docs/08 §13): "Needs you" (deferred: it diffs every
     * environment's staged changes), the team's last deployments (deferred)
     * and the project cards, problems first.
     */
    public function index(#[CurrentUser] User $user, ProjectSummaries $summaries, TeamAttention $attention, TeamRecentDeployments $recent): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [Project::class, $team]);

        $projects = null;
        $load = static function () use (&$projects, $summaries, $team): array {
            return $projects ??= $summaries->for($team);
        };

        return Inertia::render('projects/index', [
            'projects' => $load,
            'attention' => Inertia::defer(static fn (): array => $attention->for($team, $user, $load())),
            // Its own group: it doesn't wait for the staged-changes diff above.
            'recentDeployments' => Inertia::defer(static fn (): array => $recent->for($team, self::RECENT_DEPLOYMENTS), 'recent'),
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
