<?php

declare(strict_types=1);

namespace App\Http\Controllers\StagedChanges;

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\DiscardChanges;
use App\Data\Api\DeployEnvironmentInput;
use App\Data\StagedChanges\DiscardChangesData;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Http\Toast;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\ProjectShell;
use App\Queries\RecentRevisions;
use App\Queries\StagedChangesView;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staged changes (docs/06 §4): the pill summary, the review page, Deploy and
 * Discard (all or per item).
 */
final class StagedChangesController extends Controller
{
    public function show(Project $project, Environment $environment, StagedChangesView $view, RecentRevisions $revisions): Response
    {
        RouteScope::environment($project, $environment);
        Gate::authorize('view', $environment);

        return Inertia::render('projects/changes', [
            ...ProjectShell::props($project, $environment),
            'changes' => $view->get($environment, withBlockers: true),
            'revisions' => $revisions->for($environment),
        ]);
    }

    /**
     * The pill's summary (JSON). `?blockers=1` also resolves every reference
     * (the Deploy dialog shows what would refuse the deploy before submitting).
     */
    public function summary(Request $request, Project $project, Environment $environment, StagedChangesView $view): JsonResponse
    {
        RouteScope::environment($project, $environment);
        Gate::authorize('view', $environment);

        return response()->json($view->get($environment, withBlockers: $request->boolean('blockers')))->header('Cache-Control', 'no-store');
    }

    public function deploy(Request $request, Project $project, Environment $environment, DeployEnvironmentInput $input, #[CurrentUser] User $user, DeployEnvironment $deploy): RedirectResponse
    {
        RouteScope::environment($project, $environment);
        Gate::authorize('deploy', $environment);

        $result = $deploy->handle($environment, $user, $this->auditActor($request, $user), $input->revisionMessage(), holdTraffic: $input->stage);
        $count = count($result->deployments);

        Toast::success($count === 0
            ? "Revision {$result->revision->number} applied. No service needed a new deployment."
            : "Revision {$result->revision->number} applied. Deploying {$count} ".($count === 1 ? 'service' : 'services').'.');

        // From the review sheet on the canvas: stay and watch the nodes redeploy (flows §5).
        if ($request->boolean('stay')) {
            return back();
        }

        return to_route('deployments.project', ['project' => $project->public_id, 'environment' => $environment->public_id]);
    }

    public function discard(Request $request, Project $project, Environment $environment, DiscardChangesData $data, #[CurrentUser] User $user, DiscardChanges $discard): RedirectResponse
    {
        RouteScope::environment($project, $environment);
        Gate::authorize('deploy', $environment);
        $count = $discard->handle($environment, $data->paths, $this->auditActor($request, $user));

        Toast::success($count === 1 ? 'Discarded 1 change.' : "Discarded {$count} changes.");

        return back();
    }
}
