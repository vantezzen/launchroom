<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Data\Metrics\MetricsServiceData;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Support\Canvas\ServiceGlyph;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/p/{project}/{environment}/metrics`: the project Metrics tab (docs/15
 * §2.3), metric cards with deltas and stacked sparklines over the
 * environment's services. The numbers come from `projects.metrics` (JSON,
 * refetched live); the page only carries the shell and the service list.
 */
final class ProjectMetricsPageController extends Controller
{
    public function show(Project $project, Environment $environment): Response
    {
        ProjectScope::environment($project, $environment);
        Gate::authorize('view', $environment);

        return Inertia::render('projects/metrics', [
            ...ProjectScope::shellProps($project, $environment),
            'services' => $project->services()
                ->whereHas('instances', static fn (Builder $q) => $q->where('environment_id', $environment->id)->whereNull('removed_at'))
                ->orderBy('name')
                ->get()
                ->map(static fn (Service $service): MetricsServiceData => new MetricsServiceData($service->public_id, $service->name, $service->slug, ServiceGlyph::logo($service)))
                ->values()
                ->all(),
        ]);
    }
}
