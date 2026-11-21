<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Environments\ResumeAutoDeploy;
use App\Data\Api\EnvironmentResource;
use App\Data\Api\ServiceResource;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Models\Environment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\Api\ApiResources;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class EnvironmentController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'getEnvironment', summary: 'One environment', tag: 'Environments', response: EnvironmentResource::class)]
    public function show(Environment $environment, ApiResources $resources): JsonResponse
    {
        Gate::authorize('view', $environment);

        return ApiResponse::item($resources->environment($environment));
    }

    #[ApiOperation(id: 'listServices', summary: 'Services in an environment with their status', tag: 'Services', response: ServiceResource::class, list: true)]
    public function services(Environment $environment, #[CurrentUser] User $user, ApiResources $resources): JsonResponse
    {
        Gate::authorize('view', $environment);
        $scope = $this->token($user)->service_id;

        $instances = ServiceInstance::query()
            ->current()
            ->where('environment_id', $environment->id)
            // A service-scoped token lists its own service only (docs/16 §1.4).
            ->when($scope !== null, static fn ($query) => $query->where('service_id', $scope))
            ->with(['service', 'activeDeployment', 'domains'])
            ->get()
            ->each(static fn (ServiceInstance $instance) => $instance->setRelation('environment', $environment))
            ->sortBy(static fn (ServiceInstance $instance): string => $instance->service->name)
            ->values();

        return ApiResponse::list($instances->map($resources->service(...)));
    }

    #[ApiOperation(id: 'getService', summary: 'One service in an environment', tag: 'Services', response: ServiceResource::class)]
    public function service(Environment $environment, Service $service, ApiResources $resources): JsonResponse
    {
        $instance = RouteScope::instanceIn($environment, $service);
        Gate::authorize('view', $instance);

        return ApiResponse::item($resources->service($instance));
    }

    #[ApiOperation(id: 'resumeAutoDeploy', summary: 'Resume auto-deploy after a rollback', tag: 'Environments', response: EnvironmentResource::class, description: 'A production rollback pauses auto-deploy (docs/04 §8): new pushes don\'t go live until it is resumed. Nothing is deployed right away.')]
    public function resumeAutoDeploy(Request $request, Environment $environment, #[CurrentUser] User $user, ResumeAutoDeploy $resume, ApiResources $resources): JsonResponse
    {
        Gate::authorize('deploy', $environment);

        $resume->handle($environment, $this->auditActor($request, $user));

        return ApiResponse::item($resources->environment($environment->refresh()));
    }
}
