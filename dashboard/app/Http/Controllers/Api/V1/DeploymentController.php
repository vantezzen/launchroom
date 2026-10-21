<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Deployments\CancelDeployment;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\DeployRef;
use App\Actions\Deployments\RedeployService;
use App\Actions\Deployments\RollbackDeployment;
use App\Data\Api\CreateDeploymentInput;
use App\Data\Api\DeployEnvironmentInput;
use App\Data\Api\DeployEnvironmentResultData;
use App\Data\Api\DeploymentResource;
use App\Data\Api\ListDeploymentsQuery;
use App\Enums\DeploymentTrigger;
use App\Events\CanvasChanged;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Api\Cursor;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Service;
use App\Models\User;
use App\Queries\Api\ApiResources;
use App\Queries\Api\EnvironmentDeployments;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Deployments through the API (docs/16 §2.2): the same Actions and policies
 * as the dashboard's buttons.
 */
final class DeploymentController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'listDeployments', summary: 'Deployments of an environment, newest first', tag: 'Deployments', response: DeploymentResource::class, list: true, query: ListDeploymentsQuery::class, paginated: true)]
    public function index(Environment $environment, ListDeploymentsQuery $query, ApiResources $resources, EnvironmentDeployments $deployments): JsonResponse
    {
        Gate::authorize('view', $environment);

        $service = $query->service === null
            ? null
            : Service::query()->where('project_id', $environment->project_id)->where('public_id', $query->service)->firstOrFail();

        [$page, $next] = $deployments->page($environment, $service, Cursor::decode($query->cursor), $query->limit);

        return ApiResponse::list(array_map($resources->deployment(...), $page), $next === null ? null : Cursor::encode($next));
    }

    #[ApiOperation(id: 'createDeployment', summary: 'Deploy a commit of a service (or redeploy it)', tag: 'Deployments', response: DeploymentResource::class, request: CreateDeploymentInput::class, status: 202, description: 'Git services deploy `sha`, else the head of branch `ref`, else the head of their tracked branch, with the environment\'s last deployed configuration (staged changes stay staged). `redeploy: true` deploys the current commit again.')]
    public function store(Request $request, Environment $environment, CreateDeploymentInput $input, #[CurrentUser] User $user, DeployRef $deployRef, RedeployService $redeploy, ApiResources $resources): JsonResponse
    {
        $service = Service::query()->where('project_id', $environment->project_id)->where('public_id', $input->service)->firstOrFail();
        $instance = $this->instance($environment, $service);
        Gate::authorize('deploy', $environment);

        $deployment = $input->redeploy
            ? $redeploy->handle($instance, null, $user, $this->auditActor($request, $user))
            : $deployRef->handle($instance, $input->ref, $input->sha, $user, $this->auditActor($request, $user));

        broadcast(new CanvasChanged($environment->public_id));

        return ApiResponse::item($resources->deployment($deployment), 202);
    }

    #[ApiOperation(id: 'deployEnvironment', summary: 'Deploy the staged changes of an environment', tag: 'Deployments', response: DeployEnvironmentResultData::class, request: DeployEnvironmentInput::class, status: 202)]
    public function deployEnvironment(Request $request, Environment $environment, DeployEnvironmentInput $input, #[CurrentUser] User $user, DeployEnvironment $deploy, ApiResources $resources): JsonResponse
    {
        Gate::authorize('deploy', $environment);

        $message = $input->message === null ? null : trim($input->message);
        $result = $deploy->handle($environment, $user, $this->auditActor($request, $user), $message === '' ? null : $message, DeploymentTrigger::Api, holdTraffic: $input->stage);
        broadcast(new CanvasChanged($environment->public_id));

        return ApiResponse::item(new DeployEnvironmentResultData(
            revision: $result->revision->number,
            deployments: array_map($resources->deployment(...), $result->deployments),
        ), 202);
    }

    #[ApiOperation(id: 'getDeployment', summary: 'One deployment', tag: 'Deployments', response: DeploymentResource::class)]
    public function show(Deployment $deployment, ApiResources $resources): JsonResponse
    {
        Gate::authorize('view', $deployment);

        return ApiResponse::item($resources->deployment($deployment));
    }

    #[ApiOperation(id: 'redeployDeployment', summary: 'Redeploy the commit of a deployment', tag: 'Deployments', response: DeploymentResource::class, status: 202)]
    public function redeploy(Request $request, Deployment $deployment, #[CurrentUser] User $user, RedeployService $redeploy, ApiResources $resources): JsonResponse
    {
        Gate::authorize('redeploy', $deployment);

        $deployment->loadMissing('serviceInstance');
        $created = $redeploy->handle($deployment->serviceInstance, $deployment, $user, $this->auditActor($request, $user));

        return ApiResponse::item($resources->deployment($created), 202);
    }

    #[ApiOperation(id: 'rollbackDeployment', summary: 'Roll back to an earlier deployment', tag: 'Deployments', response: DeploymentResource::class, status: 202, description: 'Only to a superseded deployment whose image still exists. Database migrations are not reverted.')]
    public function rollback(Request $request, Deployment $deployment, #[CurrentUser] User $user, RollbackDeployment $rollback, ApiResources $resources): JsonResponse
    {
        Gate::authorize('rollback', $deployment);

        $created = $rollback->handle($deployment, $user, $this->auditActor($request, $user));

        return ApiResponse::item($resources->deployment($created), 202);
    }

    #[ApiOperation(id: 'cancelDeployment', summary: 'Cancel a queued, building or staged deployment', tag: 'Deployments', response: DeploymentResource::class)]
    public function cancel(Request $request, Deployment $deployment, #[CurrentUser] User $user, CancelDeployment $cancel, ApiResources $resources): JsonResponse
    {
        Gate::authorize('cancel', $deployment);

        $cancel->handle($deployment, $this->auditActor($request, $user));

        return ApiResponse::item($resources->deployment($deployment->refresh()));
    }
}
