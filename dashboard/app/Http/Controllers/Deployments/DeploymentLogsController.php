<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployments;

use App\Data\Api\LogCursorQuery;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Queries\DeploymentLogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Log lines of a deployment after a cursor ("load more" and live follow).
 */
final class DeploymentLogsController extends Controller
{
    public function __invoke(Project $project, Environment $environment, Deployment $deployment, LogCursorQuery $cursor, DeploymentLogs $logs): JsonResponse
    {
        RouteScope::deployment($project, $environment, $deployment);
        Gate::authorize('view', $deployment);

        return response()->json($logs->page($deployment, $cursor->after))->header('Cache-Control', 'no-store');
    }
}
