<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TokenScopeLevel;
use App\Exceptions\ApiError;
use App\Http\Api\TokenScopes;
use App\Models\ApiToken;
use App\Models\Commands\CommandRun;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Previews\Preview;
use App\Models\Project;
use App\Models\Security\SecurityFinding;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use App\Models\Variable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a scoped token (one project, or one service of it) inside its scope
 * on every API route (docs/16 §1.4, docs/09 R45). Runs after the bindings:
 * the route's level must admit the scope (App\Http\Api\TokenScopes), every
 * bound model must lie in the scope (a class without a resolver here fails
 * closed), and a route that acts on the service named in its `service`
 * input must name the token's service. Unscoped tokens pass untouched.
 */
final class EnforceTokenScope
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentApiToken() : null;

        if (! $token instanceof ApiToken || ! $token->isScoped()) {
            return $next($request);
        }

        $route = $request->route();
        $name = $route?->getName();
        $scope = $token->service_id !== null ? TokenScopeLevel::Service : TokenScopeLevel::Project;
        $level = TokenScopes::of($name);

        if (! $level instanceof TokenScopeLevel || ! $level->admits($scope)) {
            throw $this->refused();
        }

        $namesService = false;

        foreach ($route?->parameters() ?? [] as $parameter) {
            if (! $parameter instanceof Model) {
                continue;
            }

            [$projectId, $serviceId] = $this->owner($parameter);

            if ($projectId !== $token->project_id) {
                throw $this->refused();
            }

            // Projects and environments only hold the service; anything else
            // that has no service of its own (shared variables) is project-wide.
            if ($parameter instanceof Project || $parameter instanceof Environment) {
                continue;
            }

            if ($token->service_id !== null && $serviceId !== $token->service_id) {
                throw $this->refused();
            }

            $namesService = true;
        }

        if ($token->service_id !== null && ! $namesService && TokenScopes::namesService($name)) {
            $service = $request->input('service');

            if (! is_string($service) || $service !== Service::query()->whereKey($token->service_id)->value('public_id')) {
                throw $this->refused();
            }
        }

        return $next($request);
    }

    /**
     * The project and service a bound model belongs to.
     *
     * @return array{int, int|null}
     */
    private function owner(Model $model): array
    {
        return match (true) {
            $model instanceof Project => [$model->id, null],
            $model instanceof Environment => [$model->project_id, null],
            $model instanceof Service => [$model->project_id, $model->id],
            $model instanceof Variable => [$model->project_id, $model->service_id],
            $model instanceof Preview => [$model->project_id, null],
            $model instanceof ServiceInstance => $this->instance($model->id),
            $model instanceof Deployment,
            $model instanceof Domain,
            $model instanceof CommandRun,
            $model instanceof SecurityFinding,
            $model instanceof DataTransfer,
            $model instanceof DataImportRun,
            $model instanceof DataExportRun => $this->instance($model->service_instance_id),
            default => throw $this->refused(),
        };
    }

    /**
     * @return array{int, int}
     */
    private function instance(int $instanceId): array
    {
        $service = Service::query()
            ->whereIn('id', ServiceInstance::query()->select('service_id')->whereKey($instanceId))
            ->first(['id', 'project_id']);

        return $service instanceof Service ? [$service->project_id, $service->id] : throw $this->refused();
    }

    private function refused(): ApiError
    {
        return new ApiError('token_scope', 'This token is limited to another project or service.', 403);
    }
}
