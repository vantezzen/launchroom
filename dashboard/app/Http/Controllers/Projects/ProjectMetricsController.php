<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Data\Metrics\MetricsQueryData;
use App\Data\Metrics\ProjectMetricsData;
use App\Enums\EnvironmentKind;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Queries\ProjectMetrics;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /p/{project}/-/metrics?range=&environment=&service= (docs/15): per-service
 * usage of one environment (default: production) for canvas nodes and the
 * service panel's Metrics tab. Ids are resolved inside the project only.
 */
final class ProjectMetricsController extends Controller
{
    public function __invoke(Request $request, Project $project, ProjectMetrics $metrics): ProjectMetricsData
    {
        Gate::authorize('view', $project);
        $query = MetricsQueryData::validateAndCreate($request->query());

        $environment = $project->environments()
            ->when(
                $query->environment !== null,
                static fn ($q) => $q->where('public_id', $query->environment),
                static fn ($q) => $q->where('kind', EnvironmentKind::Production->value),
            )
            ->firstOrFail();

        $instance = null;

        if ($query->service !== null) {
            $instance = ServiceInstance::query()
                ->where('environment_id', $environment->id)
                ->whereHas('service', static fn (Builder $q) => $q->where('public_id', $query->service)->where('project_id', $project->id))
                ->firstOrFail();
        }

        return $metrics->handle($project, $environment, $instance, $query->range);
    }
}
