<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Deployments\DeployLatest;
use App\Actions\Services\RestartService;
use App\Actions\Services\StartService;
use App\Actions\Services\StopService;
use App\Data\Api\DeploymentResource;
use App\Data\Api\ServiceActionInput;
use App\Data\Api\ServiceActionResultData;
use App\Events\CanvasChanged;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Service;
use App\Models\User;
use App\Queries\Api\ApiResources;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Deploy, Restart, Stop and Start of a service in an environment through
 * the API (D68, docs/16 §2.2): the same Actions as the service panel's
 * header, with the environment's `deploy` permission, audited by the
 * actions.
 */
final class ServiceActionController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'deployService', summary: 'Deploy the latest version of a service', tag: 'Services', response: DeploymentResource::class, status: 202, description: 'Builds and deploys the latest commit of the branch the service tracks, or resolves an image service\'s tag again. Refused while the service is stopped or has staged changes (deploy those with `POST /environments/{environment}/deploy`). Databases, workers and crons restart instead.')]
    public function deploy(Request $request, Environment $environment, Service $service, #[CurrentUser] User $user, DeployLatest $deploy, ApiResources $resources): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('deploy', $environment);

        $deployment = $deploy->handle($instance, $user, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id));

        return ApiResponse::item($resources->deployment($deployment), 202);
    }

    #[ApiOperation(id: 'restartService', summary: 'Restart a running service', tag: 'Services', response: ServiceActionResultData::class, request: ServiceActionInput::class, status: 202, description: 'Starts new containers of the live version (same image, configuration and environment, no build) through the usual rollout, one replica at a time when there are several. Refused while a deployment of the service is in progress.')]
    public function restart(Request $request, Environment $environment, Service $service, ServiceActionInput $input, #[CurrentUser] User $user, RestartService $restart, ApiResources $resources): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('deploy', $environment);

        $created = $restart->handle($instance, $input->processes, $user, $this->auditActor($request, $user));

        return $this->result($environment, $service, $created, $resources);
    }

    #[ApiOperation(id: 'stopService', summary: 'Stop a service', tag: 'Services', response: ServiceActionResultData::class, request: ServiceActionInput::class, description: 'Its containers stop and its domains show a stopped page. Configuration, domains, volumes and deployments stay; unfinished deployments are cancelled. Applies at once, not as a staged change.')]
    public function stop(Request $request, Environment $environment, Service $service, ServiceActionInput $input, #[CurrentUser] User $user, StopService $stop, ApiResources $resources): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('deploy', $environment);

        $stop->handle($instance, $input->processes, $user, $this->auditActor($request, $user));

        return $this->result($environment, $service, [], $resources, 200);
    }

    #[ApiOperation(id: 'startService', summary: 'Start a stopped service', tag: 'Services', response: ServiceActionResultData::class, request: ServiceActionInput::class, status: 202, description: 'Runs the version that was live when it stopped, with the configuration deployed last, through the usual rollout.')]
    public function start(Request $request, Environment $environment, Service $service, ServiceActionInput $input, #[CurrentUser] User $user, StartService $start, ApiResources $resources): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('deploy', $environment);

        $created = $start->handle($instance, $input->processes, $user, $this->auditActor($request, $user));

        return $this->result($environment, $service, $created, $resources);
    }

    /**
     * @param  list<Deployment>  $deployments
     */
    private function result(Environment $environment, Service $service, array $deployments, ApiResources $resources, int $status = 202): JsonResponse
    {
        broadcast(new CanvasChanged($environment->public_id));

        return ApiResponse::item(new ServiceActionResultData(
            service: $resources->service($this->instance($environment, $service)),
            deployments: array_map($resources->deployment(...), $deployments),
        ), $status);
    }
}
