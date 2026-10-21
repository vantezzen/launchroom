<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployments;

use App\Data\Variables\EnvironmentOptionData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectScope;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Queries\DeploymentStats;
use App\Queries\DeploymentSummaries;
use App\Support\Canvas\ServiceGlyph;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * All deployments of a project across environments, filterable by
 * environment, service and status (docs/08 §2, research §5.7). The page
 * groups rows by commit.
 */
final class ProjectDeploymentsController extends Controller
{
    private const int LIMIT = 200;

    public function __invoke(Request $request, Project $project, DeploymentSummaries $summaries, DeploymentStats $stats): Response
    {
        Gate::authorize('view', $project);

        $request->validate([
            'environment' => ['nullable', 'string', 'max:40'],
            'service' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', Rule::in(['in_progress', 'ready', 'active', 'failed', 'cancelled', 'superseded', 'queued'])],
        ]);

        $environments = $project->environments()->orderByRaw("CASE kind WHEN 'production' THEN 0 WHEN 'persistent' THEN 1 ELSE 2 END")->orderBy('name')->get();
        $services = $project->services()->orderBy('name')->get();

        $environment = $environments->firstWhere('public_id', $request->string('environment')->value());
        $service = $services->firstWhere('public_id', $request->string('service')->value());
        $status = $request->filled('status') ? $request->string('status')->value() : null;

        $environmentIds = array_values($environments->map(static fn (Environment $env): int => $env->id)->all());

        $deployments = $summaries->forProject(
            $project->team_id,
            $environmentIds,
            $environment?->id,
            $service?->id,
            $status,
            self::LIMIT,
        );

        return Inertia::render('projects/deployments/index', [
            ...ProjectScope::shellProps($project, $environment instanceof Environment ? $environment : null),
            'deployments' => $deployments,
            // Header strip and per-service medians for the duration bars (direction.md §8.3).
            'stats' => $stats->summary($project->team_id, $environment instanceof Environment ? [$environment->id] : $environmentIds, $service?->id),
            'environmentOptions' => array_values($environments->map(static fn (Environment $env): EnvironmentOptionData => new EnvironmentOptionData($env->public_id, $env->name, $env->kind))->all()),
            // The logo of each service for the rows (canvas glyph vocabulary).
            'serviceOptions' => array_values($services->map(static fn (Service $svc): array => ['id' => $svc->public_id, 'name' => $svc->name, 'logo' => ServiceGlyph::logo($svc)])->all()),
            'filters' => [
                'environment' => $environment?->public_id,
                'service' => $service?->public_id,
                'status' => $status,
            ],
        ]);
    }
}
