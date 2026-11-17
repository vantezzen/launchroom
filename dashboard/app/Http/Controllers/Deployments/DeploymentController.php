<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployments;

use App\Actions\Deployments\ApplyFailureFix;
use App\Actions\Deployments\CancelDeployment;
use App\Actions\Deployments\RedeployService;
use App\Actions\Deployments\RollbackDeployment;
use App\Data\Deployments\DeploymentDetailData;
use App\Data\Deployments\DeploymentImpactData;
use App\Data\Deployments\FailureFixData;
use App\Data\Deployments\LogPageData;
use App\Enums\FailureFixAction;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Http\Toast;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\DeploymentDetailView;
use App\Queries\DeploymentImpact;
use App\Queries\DeploymentLogs;
use App\Queries\ProjectShell;
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
    public function show(Project $project, Environment $environment, Deployment $deployment, DeploymentDetailView $view, DeploymentLogs $logs, DeploymentImpact $impact): Response
    {
        RouteScope::deployment($project, $environment, $deployment);
        Gate::authorize('view', $deployment);

        return Inertia::render('projects/deployments/show', [
            ...ProjectShell::props($project, $environment),
            // Lazy: a live deploy reloads `detail` on every status event
            // without building the first page of lines again.
            'detail' => static fn (): DeploymentDetailData => $view->get($deployment),
            'logs' => static fn (): LogPageData => $logs->page($deployment),
            // "Since this deploy" (docs/08 §12): its own prop, so status
            // reloads of `detail` don't recompute it.
            'impact' => static fn (): ?DeploymentImpactData => $impact->for($deployment),
        ]);
    }

    public function rollback(Request $request, Project $project, Environment $environment, Deployment $deployment, #[CurrentUser] User $user, RollbackDeployment $rollback): RedirectResponse
    {
        RouteScope::deployment($project, $environment, $deployment);
        Gate::authorize('rollback', $deployment);

        $created = $rollback->handle($deployment, $user, $this->auditActor($request, $user));
        Toast::success('Rolling back. Database migrations are not reverted.');

        return to_route('deployments.show', [$project->public_id, $environment->public_id, $created->public_id]);
    }

    public function redeploy(Request $request, Project $project, Environment $environment, Deployment $deployment, #[CurrentUser] User $user, RedeployService $redeploy): RedirectResponse
    {
        RouteScope::deployment($project, $environment, $deployment);
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
        RouteScope::deployment($project, $environment, $deployment);
        $deployment->loadMissing('serviceInstance');
        Gate::authorize('update', $deployment->serviceInstance);
        Gate::authorize('deploy', $environment);

        $created = $fix->handle($deployment, $data, $user, $this->auditActor($request, $user));

        if ($data->action === FailureFixAction::FreeDisk) {
            Toast::success('launchroom is freeing space on the server. Deploy again once it is done.');

            return back();
        }

        if (! $created instanceof Deployment) {
            Toast::success('Fix staged. Other changes are staged too: review and apply them together.');

            return to_route('staged-changes.show', [$project->public_id, $environment->public_id]);
        }

        Toast::success('Fix staged and deploying.');

        return to_route('deployments.show', [$project->public_id, $environment->public_id, $created->public_id]);
    }

    public function cancel(Request $request, Project $project, Environment $environment, Deployment $deployment, #[CurrentUser] User $user, CancelDeployment $cancel): RedirectResponse
    {
        RouteScope::deployment($project, $environment, $deployment);
        Gate::authorize('cancel', $deployment);

        $cancel->handle($deployment, $this->auditActor($request, $user));
        Toast::success('Deployment cancelled.');

        return back();
    }
}
