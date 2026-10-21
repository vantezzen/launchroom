<?php

declare(strict_types=1);

namespace App\Http\Controllers\StagedChanges;

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\DiscardChanges;
use App\Data\StagedChanges\RevisionData;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Deployments\ScopesProjectRoutes;
use App\Http\Controllers\Projects\ProjectScope;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\Project;
use App\Models\User;
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
    use ScopesProjectRoutes;

    public function show(Project $project, Environment $environment, StagedChangesView $view): Response
    {
        $this->scopeEnvironment($project, $environment);
        Gate::authorize('view', $environment);

        return Inertia::render('projects/changes', [
            ...ProjectScope::shellProps($project, $environment),
            'changes' => $view->get($environment, withBlockers: true),
            // "Revert to revision N" (docs/06 §6.5).
            'revisions' => EnvironmentRevision::query()
                ->where('environment_id', $environment->id)
                ->with('creator:id,name')
                ->latest('number')
                ->limit(10)
                ->get(['id', 'public_id', 'environment_id', 'number', 'message', 'created_by', 'created_at'])
                ->map(static fn (EnvironmentRevision $revision): RevisionData => new RevisionData(
                    $revision->public_id,
                    $revision->number,
                    $revision->message,
                    $revision->created_at->toIso8601String(),
                    $revision->creator?->name,
                    $revision->id === $environment->last_applied_revision_id,
                ))
                ->values()
                ->all(),
        ]);
    }

    /**
     * The pill's summary (JSON). `?blockers=1` also resolves every reference
     * (the Deploy dialog shows what would refuse the deploy before submitting).
     */
    public function summary(Request $request, Project $project, Environment $environment, StagedChangesView $view): JsonResponse
    {
        $this->scopeEnvironment($project, $environment);
        Gate::authorize('view', $environment);

        return response()->json($view->get($environment, withBlockers: $request->boolean('blockers')))->header('Cache-Control', 'no-store');
    }

    public function deploy(Request $request, Project $project, Environment $environment, #[CurrentUser] User $user, DeployEnvironment $deploy): RedirectResponse
    {
        $this->scopeEnvironment($project, $environment);
        Gate::authorize('deploy', $environment);

        $request->validate(['message' => ['nullable', 'string', 'max:500'], 'stage' => ['sometimes', 'boolean'], 'stay' => ['sometimes', 'boolean']]);
        $message = $request->string('message')->trim()->value();

        // `stage`: built and started, routed only on Promote (docs/06 §6.5).
        $result = $deploy->handle($environment, $user, $this->auditActor($request, $user), $message === '' ? null : $message, holdTraffic: $request->boolean('stage'));
        $count = count($result->deployments);
        broadcast(new CanvasChanged($environment->public_id));

        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 0
            ? "Revision {$result->revision->number} applied. No service needed a new deployment."
            : "Revision {$result->revision->number}: deploying {$count} ".($count === 1 ? 'service' : 'services').'.']);

        // From the review sheet on the canvas: stay and watch the nodes deploy (flows §5).
        if ($request->boolean('stay')) {
            return back();
        }

        return to_route('deployments.project', ['project' => $project->public_id, 'environment' => $environment->public_id]);
    }

    public function discard(Request $request, Project $project, Environment $environment, #[CurrentUser] User $user, DiscardChanges $discard): RedirectResponse
    {
        $this->scopeEnvironment($project, $environment);
        Gate::authorize('deploy', $environment);

        $request->validate([
            'paths' => ['nullable', 'array', 'max:500'],
            'paths.*' => ['string', 'max:255', 'regex:/^[A-Za-z]+(?:\.[A-Za-z0-9_.-]+)*$/'],
        ]);

        $paths = $request->has('paths') ? array_values(array_filter($request->array('paths'), is_string(...))) : null;
        $count = $discard->handle($environment, $paths, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id));

        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 1 ? 'Discarded 1 change.' : "Discarded {$count} changes."]);

        return back();
    }
}
