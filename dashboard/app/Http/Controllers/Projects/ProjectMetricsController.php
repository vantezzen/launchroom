<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Data\Metrics\MetricsQueryData;
use App\Data\Metrics\ProjectMetricsData;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Models\Project;
use App\Queries\ProjectMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /p/{project}/-/metrics?range=&environment=&service= (docs/15): per-service
 * usage of one environment (default: production) for canvas nodes and the
 * Resources view of the Analytics tab. Ids are resolved inside the project only.
 */
final class ProjectMetricsController extends Controller
{
    public function __invoke(Request $request, Project $project, ProjectMetrics $metrics): ProjectMetricsData
    {
        Gate::authorize('view', $project);
        $query = MetricsQueryData::validateAndCreate($request->query());

        $environment = RouteScope::environmentOrProduction($project, $query->environment);

        return $metrics->handle($project, $environment, RouteScope::serviceInstance($environment, $query->service), $query->range);
    }
}
