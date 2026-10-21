<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployments;

use App\Actions\Deployments\ApplyFailureFix;
use App\Actions\Deployments\CancelDeployment;
use App\Actions\Deployments\RedeployService;
use App\Actions\Deployments\RollbackDeployment;
use App\Data\Deployments\FailureFixData;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectScope;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\DeploymentDetailView;
use App\Queries\DeploymentLogs;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deployment detail page and its actions (docs/08 §3.5).
 */
final class DeploymentController extends Controller
{
    use ScopesProjectRoutes;

    public function show(Project $project, Environment $environment, Deployment $deployment, DeploymentDetailView $view, DeploymentLogs $logs): Response
    {
        $this->scopeDeployment($project, $environment, $deployment);
        Gate::authorize('view', $deployment);

        return Inertia::render('projects/deployments/show', [
            ...ProjectScope::shellProps($project, $environment),
            'detail' => $view->get($deployment),
            'logs' => $logs->page($deployment),
        ]);
    }

    public function rollback(Request $request, Project $project, Environment $environment, Deployment $deployment, #[CurrentUser] User $user, RollbackDeployment $rollback): RedirectResponse
    {
        $this->scopeDeployment($project, $environment, $deployment);
        Gate::authorize('rollback', $deployment);

        $created = $rollback->handle($deployment, $user, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rolling back. Database migrations are not reverted.']);

        return to_route('deployments.show', [$project->public_id, $environment->public_id, $created->public_id]);
    }

    public function redeploy(Request $request, Project $project, Environment $environment, Deployment $deployment, #[CurrentUser] User $user, RedeployService $redeploy): RedirectResponse
    {
        $this->scopeDeployment($project, $environment, $deployment);
        Gate::authorize('redeploy', $deployment);

        $deployment->loadMissing('serviceInstance');
        $created = $redeploy->handle($deployment->serviceInstance, $deployment, $user, $this->auditActor($request, $user));

        return to_route('deployments.show', [$project->public_id, $environment->public_id, $created->public_id]);
    }

    /**
     * "Fix and redeploy" on an explained failure (flows §6): stages the
     * suggested setting; deploys it when nothing else is staged.
     */
    public function fix(Request $request, Project $project, Environment $environment, Deployment $deployment, FailureFixData $data, #[CurrentUser] User $user, ApplyFailureFix $fix): RedirectResponse
    {
        $this->scopeDeployment($project, $environment, $deployment);
        $deployment->loadMissing('serviceInstance');
        Gate::authorize('update', $deployment->serviceInstance);
        Gate::authorize('deploy', $environment);

        $created = $fix->handle($deployment, $data, $user, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id));

        if (! $created instanceof Deployment) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Fix staged. Other changes are staged too: review and deploy them together.']);

            return to_route('staged-changes.show', [$project->public_id, $environment->public_id]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fix staged and deploying.']);

        return to_route('deployments.show', [$project->public_id, $environment->public_id, $created->public_id]);
    }

    public function cancel(Request $request, Project $project, Environment $environment, Deployment $deployment, #[CurrentUser] User $user, CancelDeployment $cancel): RedirectResponse
    {
        $this->scopeDeployment($project, $environment, $deployment);
        Gate::authorize('cancel', $deployment);

        $cancel->handle($deployment, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Deployment cancelled.']);

        return back();
    }
}
