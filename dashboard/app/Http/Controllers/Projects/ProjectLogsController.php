<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Logs\StartLogTail;
use App\Actions\Logs\StopLogTail;
use App\Data\Logs\LogExplorerPageData;
use App\Data\Logs\LogQueryData;
use App\Data\Logs\LogTailData;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\EnvironmentLogs;
use App\Queries\LogSearch;
use App\Support\Logs\LogScope;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/p/{project}/{environment}/logs`: the project's Logs tab, a log explorer
 * over the environment's build and runtime lines (direction.md §9.8), plus
 * its JSON endpoints: the search over the environment (`…/{environment}/-/logs`)
 * or every environment of the project (`/p/{project}/-/logs`, docs/08 §12)
 * and starting/stopping live runtime tails (`-/logs/tail`). Reading logs is
 * the View permission; tails are tagged with the viewer so they stop with
 * their explorer.
 */
final class ProjectLogsController extends Controller
{
    public function show(Project $project, Environment $environment): Response
    {
        ProjectScope::environment($project, $environment);
        Gate::authorize('view', $environment);

        return Inertia::render('projects/logs', [
            ...ProjectScope::shellProps($project, $environment),
            'services' => $project->services()
                ->whereHas('instances', static fn (Builder $q) => $q->where('environment_id', $environment->id))
                ->orderBy('name')
                ->get(['id', 'public_id', 'name', 'slug'])
                ->map(static fn (Service $service): array => ['id' => $service->public_id, 'name' => $service->name, 'slug' => $service->slug])
                ->values()
                ->all(),
        ]);
    }

    public function entries(Project $project, Environment $environment, LogQueryData $query, #[CurrentUser] User $user, EnvironmentLogs $logs): LogExplorerPageData
    {
        ProjectScope::environment($project, $environment);
        Gate::authorize('view', $environment);

        return $logs->page($environment, $query, $user);
    }

    /**
     * Search across every environment and service of the project.
     */
    public function projectEntries(Project $project, LogQueryData $query, LogSearch $logs): LogExplorerPageData
    {
        Gate::authorize('view', $project);

        return $logs->page(LogScope::project($project), $query);
    }

    public function startTail(Project $project, Environment $environment, LogTailData $data, #[CurrentUser] User $user, StartLogTail $start): JsonResponse
    {
        ProjectScope::environment($project, $environment);
        Gate::authorize('view', $environment);

        $instance = null;

        if ($data->service !== null) {
            $service = $project->services()->where('public_id', $data->service)->first();
            abort_unless($service instanceof Service, 404);
            $instance = ProjectScope::instance($project, $environment, $service);
        }

        return response()->json(['following' => $start->handle($environment, $user, $instance instanceof ServiceInstance ? $instance : null)]);
    }

    public function stopTail(Project $project, Environment $environment, #[CurrentUser] User $user, StopLogTail $stop): JsonResponse
    {
        ProjectScope::environment($project, $environment);
        Gate::authorize('view', $environment);

        return response()->json(['stopped' => $stop->handle($environment, $user)]);
    }
}
