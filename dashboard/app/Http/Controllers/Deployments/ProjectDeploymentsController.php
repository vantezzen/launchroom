<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployments;

use App\Data\Deployments\DeploymentListFilterData;
use App\Data\Variables\EnvironmentOptionData;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Queries\DeploymentStats;
use App\Queries\DeploymentSummaries;
use App\Queries\ProjectShell;
use App\Support\Canvas\ServiceGlyph;
use Illuminate\Support\Facades\Gate;
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

    public function __invoke(Project $project, DeploymentListFilterData $filter, DeploymentSummaries $summaries, DeploymentStats $stats): Response
    {
        Gate::authorize('view', $project);

        $environments = $project->environments()->inDisplayOrder()->get();
        $services = $project->services()->orderBy('name')->get();

        $environment = $environments->firstWhere('public_id', $filter->environment);
        $service = $services->firstWhere('public_id', $filter->service);
        $status = $filter->status;

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
            ...ProjectShell::props($project, $environment instanceof Environment ? $environment : null),
            'deployments' => $deployments,
            // Header strip and per-service medians for the duration bars (direction.md §8.3).
            'stats' => $stats->summary($project->team_id, $environment instanceof Environment ? [$environment->id] : $environmentIds, $service?->id),
            'environmentOptions' => array_values($environments->map(static fn (Environment $env): EnvironmentOptionData => new EnvironmentOptionData($env->public_id, $env->name, $env->kind))->all()),
            'serviceOptions' => array_values($services->map(static fn (Service $svc): array => ['id' => $svc->public_id, 'name' => $svc->name, 'logo' => ServiceGlyph::logo($svc)])->all()),
            'filters' => [
                'environment' => $environment?->public_id,
                'service' => $service?->public_id,
                'status' => $status,
            ],
        ]);
    }
}
