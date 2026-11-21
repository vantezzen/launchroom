<?php

declare(strict_types=1);

namespace App\Http\Api\OpenApi;

use App\Data\Api\Events\StreamReconnectEvent;
use App\Enums\ApiAbility;
use App\Enums\TokenScopeLevel;
use App\Http\Api\ApiOperation;
use App\Http\Api\EventStream;
use App\Http\Api\TokenScopes;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\RequireTokenAbility;
use BackedEnum;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LogicException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Builds the OpenAPI 3.1 document of /api/v1 from the routes and their
 * `#[ApiOperation]` attributes (docs/16 §2.4): paths and path parameter
 * patterns from the route, the ability from its RequireTokenAbility
 * middleware, idempotency from IdempotentRequest, schemas from the Data
 * classes. Deterministic: the same code always gives the same bytes.
 */
final readonly class OpenApiGenerator
{
    private const string PREFIX = 'api/v1/';

    public function __construct(
        private Router $router,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $schemas = new SchemaBuilder;
        $paths = [];
        $tags = [];

        $routes = array_filter($this->router->getRoutes()->getRoutes(), static fn (Route $route): bool => str_starts_with($route->uri(), self::PREFIX));
        usort($routes, static fn (Route $a, Route $b): int => [$a->uri(), $a->methods()[0]] <=> [$b->uri(), $b->methods()[0]]);

        foreach ($routes as $route) {
            $operation = $this->attribute($route);
            $path = '/'.substr($route->uri(), strlen(self::PREFIX));
            $method = strtolower(array_values(array_diff($route->methods(), ['HEAD']))[0] ?? 'get');
            $tags[$operation->tag] = true;

            $paths[$path] ??= [];
            $paths[$path][$method] = $this->operation($route, $operation, $schemas);
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'launchroom API',
                'version' => '1',
                'summary' => 'Deploy, inspect and configure launchroom projects from scripts, CI and the launchroom CLI.',
                'description' => 'Bearer tokens from Settings → API tokens (or `launchroom login`). A token is bound to one team and acts as its owner there, narrowed to its abilities. Errors are `{"error": {"code", "message"}}`; lists are `{"data": [...], "meta": {"nextCursor"}}`.',
                'license' => ['name' => 'MIT', 'identifier' => 'MIT'],
            ],
            'jsonSchemaDialect' => 'https://spec.openapis.org/oas/3.1/dialect/base',
            'servers' => [['url' => '/api/v1', 'description' => 'This launchroom']],
            'tags' => array_map(static fn (string $tag): array => ['name' => $tag], array_keys($tags)),
            'paths' => $paths,
            'components' => [
                'schemas' => [...$schemas->components(), ...$this->sharedSchemas()],
                'responses' => $this->errorResponses(),
                'securitySchemes' => [
                    'token' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'A launchroom API token (`<id>|lr_pat_…`).'],
                ],
            ],
        ];
    }

    /**
     * Path parameters bound to a backed enum (implicit enum binding): their values.
     *
     * @return array<string, list<string|int>>
     */
    private function enumParameters(Route $route): array
    {
        $action = $route->getActionName();
        [$class, $method] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];
        $enums = [];

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return [];
        }

        foreach (new ReflectionMethod($class, $method)->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

            if ($name !== null && enum_exists($name) && is_subclass_of($name, BackedEnum::class)) {
                $enums[$parameter->getName()] = array_map(static fn (BackedEnum $case): string|int => $case->value, $name::cases());
            }
        }

        return $enums;
    }

    private function attribute(Route $route): ApiOperation
    {
        $action = $route->getActionName();
        [$class, $method] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];

        if (! class_exists($class) || ! method_exists($class, $method)) {
            throw new LogicException("API route {$route->uri()} has no controller method.");
        }

        $attributes = new ReflectionMethod($class, $method)->getAttributes(ApiOperation::class);

        if ($attributes === []) {
            throw new LogicException("{$action} ({$route->uri()}) has no #[ApiOperation].");
        }

        return $attributes[0]->newInstance();
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, ApiOperation $operation, SchemaBuilder $schemas): array
    {
        $middleware = array_values(array_filter($route->gatherMiddleware(), is_string(...)));
        $authenticated = array_any($middleware, static fn (string $m): bool => $m === 'auth:sanctum' || str_starts_with($m, Authenticate::class));
        $abilities = [];

        foreach ($middleware as $m) {
            if (str_starts_with($m, RequireTokenAbility::class.':')) {
                $abilities[] = ApiAbility::from(substr($m, strlen(RequireTokenAbility::class) + 1));
            }
        }

        // The route's own ability (the most specific) names the operation; a route that needs two lists both.
        $ability = $abilities === [] ? null : $abilities[count($abilities) - 1];
        $enums = $this->enumParameters($route);

        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            if (! is_string($name)) {
                continue;
            }

            $pattern = $route->wheres[$name] ?? null;
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => isset($enums[$name])
                    ? ['type' => 'string', 'enum' => $enums[$name]]
                    : array_filter(['type' => 'string', 'pattern' => is_string($pattern) ? '^'.$pattern.'$' : null]),
            ];
        }

        if ($operation->query !== null) {
            $parameters = [...$parameters, ...$schemas->queryParameters($operation->query)];
        }

        if (in_array(IdempotentRequest::class, $middleware, true)) {
            $parameters[] = [
                'name' => IdempotentRequest::HEADER,
                'in' => 'header',
                'required' => false,
                'description' => 'Replays return the first response (24 h); the same key for another request is refused.',
                'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_.:-]{1,255}$'],
            ];
        }

        $description = trim(implode("\n\n", array_filter([
            $operation->description,
            match (count($abilities)) {
                0 => null,
                1 => "Requires the `{$abilities[0]->value}` ability.",
                default => 'Requires the '.implode(' and ', array_map(static fn (ApiAbility $a): string => "`{$a->value}`", $abilities)).' abilities.',
            },
        ])));

        return array_filter([
            'operationId' => $operation->id,
            'summary' => $operation->summary,
            'description' => $description === '' ? null : $description,
            'tags' => [$operation->tag],
            'x-launchroom-ability' => $ability?->value,
            // The narrowest token scope the route serves (docs/16 §1.4).
            'x-launchroom-scope' => $authenticated ? (TokenScopes::of($route->getName()) ?? TokenScopeLevel::Team)->value : null,
            'x-launchroom-abilities' => count($abilities) > 1 ? array_map(static fn (ApiAbility $a): string => $a->value, $abilities) : null,
            'security' => $authenticated ? [['token' => []]] : [],
            'parameters' => $parameters === [] ? null : $parameters,
            'requestBody' => $operation->request === null ? null : [
                'required' => true,
                'content' => ['application/json' => ['schema' => $schemas->reference($operation->request)]],
            ],
            'responses' => $this->responses($operation, $schemas, $authenticated),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function responses(ApiOperation $operation, SchemaBuilder $schemas, bool $authenticated): array
    {
        $status = (string) $operation->status;

        $success = match (true) {
            $operation->events !== null => [
                'description' => 'Server-sent events.',
                'content' => ['text/event-stream' => ['schema' => ['type' => 'string']]],
                'x-launchroom-events' => array_map($schemas->reference(...), [...$operation->events, EventStream::RECONNECT => StreamReconnectEvent::class]),
            ],
            $operation->status === 204 || $operation->response === null => ['description' => 'Done.'],
            $operation->list => ['description' => 'OK', 'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'required' => ['data', 'meta'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => $schemas->reference($operation->response)],
                    'meta' => ['$ref' => '#/components/schemas/ListMeta'],
                ],
            ]]]],
            default => ['description' => 'OK', 'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => ['data' => $schemas->reference($operation->response)],
            ]]]],
        };

        $responses = [$status => $success];

        if ($authenticated) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
            $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
            $responses['404'] = ['$ref' => '#/components/responses/NotFound'];
        }

        $responses['422'] = ['$ref' => '#/components/responses/Invalid'];
        $responses['429'] = ['$ref' => '#/components/responses/RateLimited'];

        return $responses;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sharedSchemas(): array
    {
        return [
            'Error' => [
                'type' => 'object',
                'required' => ['error'],
                'properties' => [
                    'error' => [
                        'type' => 'object',
                        'required' => ['code', 'message'],
                        'properties' => [
                            'code' => ['type' => 'string', 'description' => 'unauthenticated, forbidden, missing_ability, not_found, method_not_allowed, conflict, validation_failed, rule_violation, idempotency_key_reused, idempotency_key_in_progress, rate_limited, server_error; CLI sign-in: authorization_pending, access_denied, expired_token.'],
                            'message' => ['type' => 'string', 'description' => 'User-facing.'],
                            'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            'ability' => ['type' => 'string', 'description' => 'The ability the token is missing (missing_ability).'],
                        ],
                    ],
                ],
            ],
            'ListMeta' => [
                'type' => 'object',
                'required' => ['nextCursor'],
                'properties' => [
                    'nextCursor' => ['type' => ['string', 'null'], 'description' => 'Pass as `cursor` for the next page; null on the last page.'],
                ],
                'additionalProperties' => true,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function errorResponses(): array
    {
        $error = static fn (string $description): array => [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];

        return [
            'Unauthenticated' => $error('The token is missing, expired or revoked.'),
            'Forbidden' => $error('The token lacks the ability (missing_ability), or the owner\'s role doesn\'t allow it (forbidden).'),
            'NotFound' => $error('Not found, or it belongs to another team.'),
            'Invalid' => $error('Invalid input (validation_failed) or a refused operation (rule_violation).'),
            'RateLimited' => $error('Too many requests; see Retry-After.'),
        ];
    }
}
