<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployments;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Queries\DeploymentLogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Log lines of a deployment after a cursor ("load more" and live follow).
 */
final class DeploymentLogsController extends Controller
{
    use ScopesProjectRoutes;

    public function __invoke(Request $request, Project $project, Environment $environment, Deployment $deployment, DeploymentLogs $logs): JsonResponse
    {
        $this->scopeDeployment($project, $environment, $deployment);
        Gate::authorize('view', $deployment);

        $request->validate(['after' => ['nullable', 'integer', 'min:0']]);
        $after = $request->integer('after');

        return response()->json($logs->page($deployment, $after))->header('Cache-Control', 'no-store');
    }
}
