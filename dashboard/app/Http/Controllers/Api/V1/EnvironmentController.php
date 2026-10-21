<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\EnvironmentResource;
use App\Data\Api\ServiceResource;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Queries\Api\ApiResources;
use Illuminate\Http\JsonResponse;
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
    public function services(Environment $environment, ApiResources $resources): JsonResponse
    {
        Gate::authorize('view', $environment);

        $instances = ServiceInstance::query()
            ->current()
            ->where('environment_id', $environment->id)
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
        $instance = $this->instance($environment, $service);
        Gate::authorize('view', $instance);

        return ApiResponse::item($resources->service($instance));
    }
}
