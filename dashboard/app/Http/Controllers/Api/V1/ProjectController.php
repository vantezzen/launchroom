<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\EnvironmentResource;
use App\Data\Api\ProjectResource;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\Api\ApiResources;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ProjectController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'listProjects', summary: 'Projects of the token\'s team', tag: 'Projects', response: ProjectResource::class, list: true)]
    public function index(#[CurrentUser] User $user, ApiResources $resources): JsonResponse
    {
        $team = $this->team($user);
        Gate::authorize('viewAny', [Project::class, $team]);

        $projects = Project::query()
            ->where('team_id', $team->id)
            ->with('environments')
            ->orderBy('name')
            ->get();

        return ApiResponse::list($projects->map($resources->project(...)));
    }

    #[ApiOperation(id: 'getProject', summary: 'One project with its environments', tag: 'Projects', response: ProjectResource::class)]
    public function show(Project $project, ApiResources $resources): JsonResponse
    {
        Gate::authorize('view', $project);

        return ApiResponse::item($resources->project($project));
    }

    #[ApiOperation(id: 'listEnvironments', summary: 'Environments of a project', tag: 'Environments', response: EnvironmentResource::class, list: true)]
    public function environments(Project $project, ApiResources $resources): JsonResponse
    {
        Gate::authorize('view', $project);

        $environments = Environment::query()
            ->where('project_id', $project->id)
            ->with(['project', 'lastAppliedRevision'])
            ->orderByRaw("CASE kind WHEN 'production' THEN 0 WHEN 'persistent' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get();

        return ApiResponse::list($environments->map($resources->environment(...)));
    }
}
