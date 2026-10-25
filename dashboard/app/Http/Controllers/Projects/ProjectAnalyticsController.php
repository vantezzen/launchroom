<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Data\Analytics\AnalyticsData;
use App\Data\Analytics\AnalyticsQueryData;
use App\Data\Metrics\MetricsServiceData;
use App\Enums\EnvironmentKind;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Queries\RequestAnalytics;
use App\Support\Canvas\ServiceGlyph;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The project Analytics tab (docs/15 §5.4): `/p/{project}/{environment}/analytics`
 * carries the shell and the service list; `GET /p/{project}/-/analytics
 * ?environment=&service=&range=` returns the numbers (JSON, refetched).
 * Ids are resolved inside the project only.
 */
final class ProjectAnalyticsController extends Controller
{
    public function show(Request $request, Project $project, Environment $environment): Response
    {
        ProjectScope::environment($project, $environment);
        Gate::authorize('view', $environment);

        $services = $project->services()
            ->whereHas('instances', static fn (Builder $q) => $q->where('environment_id', $environment->id)->whereNull('removed_at'))
            ->orderBy('name')
            ->get()
            ->map(static fn (Service $service): MetricsServiceData => new MetricsServiceData($service->public_id, $service->name, $service->slug, ServiceGlyph::logo($service)))
            ->values()
            ->all();
        $wanted = $request->query('service');
        $initial = array_find($services, static fn (MetricsServiceData $s): bool => $s->id === $wanted);

        return Inertia::render('projects/analytics', [
            ...ProjectScope::shellProps($project, $environment),
            'services' => $services,
            // ?service= preselects a service of this environment (links from the service panel).
            'initialService' => $initial?->id,
        ]);
    }

    public function data(Request $request, Project $project, RequestAnalytics $analytics): AnalyticsData
    {
        Gate::authorize('view', $project);
        $query = AnalyticsQueryData::validateAndCreate($request->query());

        $environment = $project->environments()
            ->when(
                $query->environment !== null,
                static fn ($q) => $q->where('public_id', $query->environment),
                static fn ($q) => $q->where('kind', EnvironmentKind::Production->value),
            )
            ->firstOrFail();
        Gate::authorize('view', $environment);

        [$instances, $names] = self::selection($environment, $query->service);

        return $analytics->handle($instances, $names, $query->range);
    }

    /**
     * The environment's current service instances, or the one of `service`
     * (404 when it isn't in the environment), with their names.
     *
     * @return array{list<int>, array<int, array{id: string, name: string}>}
     */
    public static function selection(Environment $environment, ?string $service): array
    {
        $instances = ServiceInstance::query()
            ->where('environment_id', $environment->id)
            ->whereNull('removed_at')
            ->when($service !== null, static fn ($q) => $q->whereHas('service', static fn (Builder $s) => $s->where('public_id', $service)->where('project_id', $environment->project_id)))
            ->with('service:id,public_id,name')
            ->get();

        abort_if($service !== null && $instances->isEmpty(), 404);

        $names = [];

        foreach ($instances as $instance) {
            $names[$instance->id] = ['id' => $instance->service->public_id, 'name' => $instance->service->name];
        }

        return [array_keys($names), $names];
    }
}
