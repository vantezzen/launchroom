<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Enums\TokenScopeLevel;

/**
 * Every authenticated API route and the narrowest token scope it serves
 * (docs/16 §1.4, docs/09 R45), read by App\Http\Middleware\EnforceTokenScope
 * and the OpenAPI document (`x-launchroom-scope`). A route that isn't listed
 * refuses scoped tokens, and a test fails for it: add every new route here.
 *
 * `service` routes bind a service-level model (a service, deployment,
 * variable, domain, run…), name the service in their `service` input
 * ({@see self::SERVICE_INPUT}), or only read the project and environment
 * around it (lists filtered by the token's scope).
 */
final class TokenScopes
{
    private const array ROUTES = [
        'api.me' => TokenScopeLevel::Own,
        // Lists filtered by the token's scope in their controllers.
        'api.projects.index' => TokenScopeLevel::Service,
        'api.projects.show' => TokenScopeLevel::Service,
        'api.projects.environments' => TokenScopeLevel::Service,
        'api.environments.show' => TokenScopeLevel::Service,
        'api.services.index' => TokenScopeLevel::Service,
        'api.services.show' => TokenScopeLevel::Service,
        'api.deployments.index' => TokenScopeLevel::Service,
        'api.deployments.show' => TokenScopeLevel::Service,
        'api.deployments.logs' => TokenScopeLevel::Service,
        'api.deployments.logs.stream' => TokenScopeLevel::Service,
        'api.environments.logs' => TokenScopeLevel::Service,
        'api.environments.logs.stream' => TokenScopeLevel::Service,
        'api.servers.index' => TokenScopeLevel::Team,
        'api.servers.show' => TokenScopeLevel::Team,
        'api.domains.index' => TokenScopeLevel::Service,
        'api.analytics.team' => TokenScopeLevel::Team,
        'api.analytics.environment' => TokenScopeLevel::Project,
        'api.analytics.service' => TokenScopeLevel::Service,
        'api.security.findings.index' => TokenScopeLevel::Team,
        'api.security.findings.show' => TokenScopeLevel::Service,
        'api.security.inventory' => TokenScopeLevel::Team,
        'api.security.waf' => TokenScopeLevel::Team,
        'api.security.service-inventory' => TokenScopeLevel::Service,
        'api.config.status' => TokenScopeLevel::Project,
        'api.config.validate' => TokenScopeLevel::Project,
        'api.config.diff' => TokenScopeLevel::Project,
        'api.config.export' => TokenScopeLevel::Project,
        'api.deployments.store' => TokenScopeLevel::Service,
        'api.environments.deploy' => TokenScopeLevel::Project,
        'api.environments.auto-deploy.resume' => TokenScopeLevel::Project,
        'api.deployments.redeploy' => TokenScopeLevel::Service,
        'api.deployments.rollback' => TokenScopeLevel::Service,
        'api.deployments.cancel' => TokenScopeLevel::Service,
        'api.deployments.failure' => TokenScopeLevel::Service,
        'api.deployments.fixes.apply' => TokenScopeLevel::Service,
        'api.deployments.skip-checks' => TokenScopeLevel::Service,
        'api.services.deploy' => TokenScopeLevel::Service,
        'api.services.restart' => TokenScopeLevel::Service,
        'api.services.stop' => TokenScopeLevel::Service,
        'api.services.start' => TokenScopeLevel::Service,
        'api.variables.index' => TokenScopeLevel::Service,
        'api.variables.value' => TokenScopeLevel::Service,
        'api.variables.env' => TokenScopeLevel::Service,
        'api.variables.store' => TokenScopeLevel::Service,
        'api.variables.update' => TokenScopeLevel::Service,
        'api.variables.destroy' => TokenScopeLevel::Service,
        'api.transfers.show' => TokenScopeLevel::Service,
        'api.transfers.chunks' => TokenScopeLevel::Service,
        'api.transfers.store' => TokenScopeLevel::Service,
        'api.transfers.complete' => TokenScopeLevel::Service,
        'api.transfers.destroy' => TokenScopeLevel::Service,
        'api.imports.show' => TokenScopeLevel::Service,
        'api.imports.store' => TokenScopeLevel::Service,
        'api.exports.show' => TokenScopeLevel::Service,
        'api.exports.store' => TokenScopeLevel::Service,
        'api.exports.link' => TokenScopeLevel::Service,
        // A one-use link only its creator can open; the export was scope-checked.
        'api.downloads.show' => TokenScopeLevel::Own,
        'api.commands.index' => TokenScopeLevel::Service,
        'api.command-runs.index' => TokenScopeLevel::Service,
        'api.command-runs.show' => TokenScopeLevel::Service,
        'api.command-runs.output' => TokenScopeLevel::Service,
        'api.command-runs.output.stream' => TokenScopeLevel::Service,
        'api.command-runs.store' => TokenScopeLevel::Service,
        'api.command-runs.cancel' => TokenScopeLevel::Service,
        'api.domains.store' => TokenScopeLevel::Service,
        'api.domains.destroy' => TokenScopeLevel::Service,
        'api.traces.decode' => TokenScopeLevel::Team,
        'api.previews.index' => TokenScopeLevel::Project,
        // Filtered by the token's scope in the controller.
        'api.app-updates.index' => TokenScopeLevel::Service,
        'api.app-updates.apply' => TokenScopeLevel::Service,
    ];

    /**
     * Routes that act on the service named in their `service` input (query
     * or body): a service-scoped token must name its own service.
     */
    private const array SERVICE_INPUT = [
        'api.deployments.index',
        'api.deployments.store',
        'api.environments.logs',
        'api.environments.logs.stream',
    ];

    public static function namesService(?string $routeName): bool
    {
        return in_array($routeName, self::SERVICE_INPUT, true);
    }

    /** The route's level; null for a route that isn't listed (scoped tokens are refused). */
    public static function of(?string $routeName): ?TokenScopeLevel
    {
        return $routeName === null ? null : (self::ROUTES[$routeName] ?? null);
    }

    /**
     * @return list<string>
     */
    public static function routeNames(): array
    {
        return array_keys(self::ROUTES);
    }
}
