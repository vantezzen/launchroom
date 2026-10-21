<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployments;

use App\Actions\Deployments\RedeployService;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Queries\DeploymentSummaries;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Service panel → Deployments (docs/08 §3.4): the active deployment and the
 * history of the service in one environment, plus "Redeploy".
 */
final class ServiceDeploymentsController extends Controller
{
    use ScopesProjectRoutes;

    private const int HISTORY = 50;

    public function index(Project $project, Environment $environment, Service $service, DeploymentSummaries $summaries): JsonResponse
    {
        $instance = $this->scopeInstance($project, $environment, $service);
        Gate::authorize('view', $instance);

        ['active' => $active, 'rows' => $rows] = $summaries->forInstance($instance, self::HISTORY);

        return response()->json([
            'active' => $active,
            'deployments' => $rows,
            'canDeploy' => Gate::allows('deploy', $environment),
        ]);
    }

    public function redeploy(Request $request, Project $project, Environment $environment, Service $service, #[CurrentUser] User $user, RedeployService $redeploy): RedirectResponse
    {
        $instance = $this->scopeInstance($project, $environment, $service);
        Gate::authorize('deploy', $environment);

        $created = $redeploy->handle($instance, null, $user, $this->auditActor($request, $user));

        return to_route('deployments.show', [$project->public_id, $environment->public_id, $created->public_id]);
    }
}
