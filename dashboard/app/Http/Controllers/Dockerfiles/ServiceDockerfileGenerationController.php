<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dockerfiles;

use App\Actions\Dockerfiles\CancelDockerfileGeneration;
use App\Actions\Dockerfiles\OpenDockerfilePullRequest;
use App\Actions\Dockerfiles\StartDockerfileGeneration;
use App\Actions\Dockerfiles\UseGeneratedDockerfile;
use App\Data\Dockerfiles\StartDockerfileGenerationData;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectScope;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Queries\DockerfileGenerationView;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Generate a Dockerfile" for one service (docs/20 §5, §7), JSON for the
 * guided sheet: start, poll, use, open a pull request, cancel.
 */
final class ServiceDockerfileGenerationController extends Controller
{
    public function store(Request $request, Project $project, Environment $environment, Service $service, StartDockerfileGenerationData $data, #[CurrentUser] User $user, StartDockerfileGeneration $start, DockerfileGenerationView $view): JsonResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('update', $instance);
        abort_if($instance->removed_at !== null, 409, 'This service is staged for removal.');

        $generation = $start->handle($instance, $user, $data->trigger, $this->auditActor($request, $user));

        return response()->json($view->generation($generation, $user), 201);
    }

    public function show(Project $project, Environment $environment, Service $service, DockerfileGeneration $generation, #[CurrentUser] User $user, DockerfileGenerationView $view): JsonResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        abort_unless($generation->service_instance_id === $instance->id, 404);
        Gate::authorize('view', $generation);

        return response()->json($view->generation($generation, $user));
    }

    public function use(Request $request, Project $project, Environment $environment, Service $service, DockerfileGeneration $generation, #[CurrentUser] User $user, UseGeneratedDockerfile $use, DockerfileGenerationView $view): JsonResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        abort_unless($generation->service_instance_id === $instance->id, 404);
        Gate::authorize('update', $generation);
        Gate::authorize('update', $instance);

        $use->handle($generation, $user, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id, $service->public_id))->toOthers();

        return response()->json($view->generation($generation->refresh(), $user));
    }

    public function pullRequest(Request $request, Project $project, Environment $environment, Service $service, DockerfileGeneration $generation, #[CurrentUser] User $user, OpenDockerfilePullRequest $open, DockerfileGenerationView $view): JsonResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        abort_unless($generation->service_instance_id === $instance->id, 404);
        Gate::authorize('update', $generation);

        $open->handle($generation, $this->auditActor($request, $user));

        return response()->json($view->generation($generation->refresh(), $user));
    }

    public function destroy(Project $project, Environment $environment, Service $service, DockerfileGeneration $generation, #[CurrentUser] User $user, CancelDockerfileGeneration $cancel, DockerfileGenerationView $view): JsonResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        abort_unless($generation->service_instance_id === $instance->id, 404);
        Gate::authorize('update', $generation);

        $cancel->handle($generation);

        return response()->json($view->generation($generation->refresh(), $user));
    }
}
