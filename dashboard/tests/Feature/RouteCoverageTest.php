<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dev\DesignVisualsController;
use App\Http\Controllers\Git\GitOAuthController;
use App\Http\Middleware\AuthenticateDaemon;
use App\Http\Middleware\SignResponse;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/*
|--------------------------------------------------------------------------
| Route coverage (docs/09-security.md R8)
|--------------------------------------------------------------------------
|
| Every route is one of:
|   1. public: listed in PUBLIC_ROUTES (small, reviewed; adding an entry is a
|      security decision; "METHOD uri" so a new verb is still caught);
|   2. daemon API (daemon/v1/*): authenticated by its request signature
|      (AuthenticateDaemon) and response-signed (SignResponse), never by a
|      session; its controllers scope everything to the signing server;
|   3. session-authenticated (`auth`), and then authorized: the app
|      controller action calls Gate::authorize()/$this->authorize(), or the
|      route has `can:` middleware, or it is in SELF_SCOPED (acts only on the
|      signed-in user's own account; reviewed list).
|
*/

const PUBLIC_ROUTES = [
    // The launchroom.json JSON Schema (docs/18 §2.2): a static, instance-free document.
    'GET schema/launchroom.v1.json',
    // Redirect to the dashboard (which requires auth).
    'GET /',
    // Health check for the container orchestrator.
    'GET up',
    // Build version for the client's reconnect probe (no session, throttled).
    'GET up/version',
    // WebAuthn discovery document (static links only).
    'GET .well-known/passkey-endpoints',

    // Fortify guest flows: login, password reset, 2FA challenge, passkey login.
    'GET login',
    'POST login',
    'GET forgot-password',
    'POST forgot-password',
    'GET reset-password/{token}',
    'POST reset-password',
    'GET two-factor-challenge',
    'POST two-factor-challenge',
    'GET passkeys/login/options',
    'POST passkeys/login',

    // First-run setup: 404 once a user exists; requires the setup token (docs/09 R10).
    'GET setup',
    'POST setup',

    // Invitation acceptance: the token (single use, expiring, e-mail-bound) is the credential.
    'GET invitations/{token}',
    'POST invitations/{token}',

    // GitHub webhooks: authenticated by the X-Hub-Signature-256 HMAC of the
    // app's webhook secret over the raw body, fail closed with 401, deduped
    // by delivery id, rate limited (docs/09 R17; tests/Feature/Git/WebhookTest.php).
    'POST webhooks/github',

    // GitLab / Gitea / Forgejo / generic git webhooks (docs/19 §5): authenticated
    // by the connection's secret (X-Gitlab-Token with hash_equals, HMAC-SHA256
    // of the raw body), fail closed with 401, deduped by delivery id, rate
    // limited (tests/Feature/Git/ProviderWebhookTest.php).
    'POST webhooks/gitlab/{connection}',
    'POST webhooks/gitea/{connection}',
    'POST webhooks/git/{connection}',

    // Docker token-auth realm (docs/05 §6): the Basic password must be a
    // registry token the dashboard issued; answers are never broader or longer
    // lived than it (tests/Feature/Registry/RegistryTokenTest.php).
    'GET registry/token',

    // Daemon installer script (docs/11 §3): static content templated with public
    // values only (URL, public signing key, daemon image); the join token is
    // never part of it (tests/Feature/Install/ServerInstallScriptTest.php).
    'GET install/server.sh',

    // HTTPS test request of Settings → Dashboard domain (docs/05 §4.1):
    // answers only an HMAC of the caller's nonce under a key used for nothing
    // else (tests/Feature/Instance/DashboardDomainTest.php).
    'GET .well-known/launchroom/dashboard',

    // Forward auth for protected previews (docs/06 §5, docs/09 R20a): asked by
    // edge Caddies for every request to a preview host. No session; answers
    // 200 only for a valid signed preview cookie or the project's bypass
    // secret, and only sets cookies from valid signed tokens
    // (tests/Feature/Previews/PreviewProtectionTest.php).
    'GET auth/preview',

    // Public API v1 (docs/16): the OpenAPI document (describes the API, no
    // instance data) and the CLI device flow, where the 256-bit device code is
    // the polling credential and nothing is granted until a signed-in member
    // approves the matching user code (tests/Feature/Api/CliLoginTest.php).
    'GET api/v1/openapi.json',
    'POST api/v1/cli/sessions',
    'POST api/v1/cli/sessions/token',

    // The new machine of a control-plane move (docs/14 §13.4, docs/09 R38):
    // the move token's 256-bit secret (compared by its SHA-256 with
    // hash_equals) is the credential, for that one move only; sealed values
    // open only with the new machine's key; answers are signed with the
    // control plane's key (tests/Feature/Moves/ControlPlaneMoveTest.php).
    'POST move/v1/claim',
    'GET move/v1/{move}',
    'GET move/v1/{move}/chunks/{index}',
    'GET move/v1/{move}/keys',
    'POST move/v1/{move}/complete',
    'POST move/v1/{move}/abort',
];

/**
 * Authenticated routes that act only on the signed-in user (no team resource).
 */
const SELF_SCOPED = [
    'App\Http\Controllers\Settings\ProfileController@edit',
    'App\Http\Controllers\Settings\ProfileController@update',
    'App\Http\Controllers\Settings\ProfileController@destroy',
    'App\Http\Controllers\Settings\SecurityController@edit',
    'App\Http\Controllers\Settings\SecurityController@update',
    // Redirects to the checklist or the projects list; reads nothing but the user's own state.
    DashboardController::class,
    // Local-only design showcase with built-in sample data; reads nothing (404 outside local).
    DesignVisualsController::class,
    // OAuth callback (docs/19 §4.1): the single-use state is bound to the
    // signed-in user, and CompleteGitProviderConnection re-checks their
    // permission on the connection's team before anything is stored.
    GitOAuthController::class,
    // GET /api/v1/me: describes the request's own token (docs/16 §2.2).
    MeController::class,
];

/**
 * @return array<string, Route> "METHOD uri" => route
 */
function routesByMethodAndUri(): array
{
    $routes = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $routes["{$method} {$route->uri()}"] = $route;
        }
    }

    return $routes;
}

/**
 * @return list<string>
 */
function middlewareOf(Route $route): array
{
    return array_values(array_filter(RouteFacade::gatherRouteMiddleware($route), is_string(...)));
}

function hasMiddleware(Route $route, string $class): bool
{
    return array_any(middlewareOf($route), fn (string $middleware): bool => $middleware === $class || str_starts_with($middleware, $class.':'));
}

function requiresAuthentication(Route $route): bool
{
    return hasMiddleware($route, Authenticate::class);
}

function isDaemonRoute(Route $route): bool
{
    return str_starts_with($route->uri(), 'daemon/v1/');
}

/**
 * Source of the route's controller method, or null for closures / non-app controllers.
 */
function appActionSource(Route $route): ?string
{
    $action = $route->getActionName();

    if (! str_starts_with($action, 'App\\Http\\Controllers\\')) {
        return null;
    }

    [$class, $method] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];
    $reflection = new ReflectionMethod($class, $method);
    $lines = file((string) $reflection->getFileName()) ?: [];

    return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
}

test('every route is public, a daemon route, or requires authentication', function (): void {
    $unprotected = [];

    foreach (routesByMethodAndUri() as $key => $route) {
        if (! in_array($key, PUBLIC_ROUTES, true) && ! isDaemonRoute($route) && ! requiresAuthentication($route)) {
            $unprotected[] = $key;
        }
    }

    expect($unprotected)->toBe([], 'These routes have no `auth` middleware and are not in PUBLIC_ROUTES.');
});

test('daemon routes are signature-authenticated, response-signed and scoped to the server', function (): void {
    $daemonRoutes = array_filter(routesByMethodAndUri(), isDaemonRoute(...));
    $violations = [];

    foreach ($daemonRoutes as $key => $route) {
        if (! hasMiddleware($route, AuthenticateDaemon::class) || ! hasMiddleware($route, SignResponse::class)) {
            $violations[] = "{$key}: missing AuthenticateDaemon or SignResponse";
        }

        if (requiresAuthentication($route) || in_array('web', $route->gatherMiddleware(), true)) {
            $violations[] = "{$key}: daemon routes must not use sessions";
        }

        // The middleware order matters: SignResponse must wrap everything.
        $middleware = middlewareOf($route);
        $sign = array_search(SignResponse::class, $middleware, true);
        $auth = array_values(array_filter(array_keys($middleware), fn (int $i): bool => str_starts_with($middleware[$i], AuthenticateDaemon::class)))[0] ?? null;
        if ($sign === false || $auth === null || $sign > $auth) {
            $violations[] = "{$key}: SignResponse must run before (outside) AuthenticateDaemon";
        }

        $source = appActionSource($route) ?? '';
        $isEnroll = hasMiddleware($route, AuthenticateDaemon::class.':'.AuthenticateDaemon::MODE_ENROLL);

        if (! $isEnroll && ! str_contains($source, 'AuthenticateDaemon::server(')) {
            $violations[] = "{$key}: controller does not scope to AuthenticateDaemon::server()";
        }
    }

    expect($daemonRoutes)->not->toBeEmpty()
        ->and($violations)->toBe([]);
});

test('every authenticated app route authorizes', function (): void {
    $unauthorized = [];

    foreach (routesByMethodAndUri() as $key => $route) {
        if (! requiresAuthentication($route) || isDaemonRoute($route)) {
            continue;
        }

        $source = appActionSource($route);

        if ($source === null) {
            continue; // Vendor (Fortify, Horizon, passkeys) and static Inertia pages.
        }

        $authorizes = str_contains($source, 'Gate::authorize(')
            || str_contains($source, '$this->authorize(')
            || array_filter(middlewareOf($route), fn (string $m): bool => str_starts_with($m, 'can:')) !== [];

        if (! $authorizes && ! in_array($route->getActionName(), SELF_SCOPED, true)) {
            $unauthorized[] = $key;
        }
    }

    expect($unauthorized)->toBe([], 'These authenticated routes never authorize (add a policy check or review SELF_SCOPED).');
});

test('the public allow-list has no stale entries', function (): void {
    $stale = array_values(array_diff(PUBLIC_ROUTES, array_keys(routesByMethodAndUri())));

    expect($stale)->toBe([], 'Remove these entries from PUBLIC_ROUTES; the routes no longer exist.');
});

test('public routes are not accidentally behind auth (keeps the list honest)', function (): void {
    $routes = routesByMethodAndUri();
    $authenticated = array_values(array_filter(
        PUBLIC_ROUTES,
        fn (string $key): bool => isset($routes[$key]) && requiresAuthentication($routes[$key]),
    ));

    expect($authenticated)->toBe([]);
});

test('registration routes do not exist (invite-only, docs/09 R10)', function (): void {
    expect(array_filter(array_keys(routesByMethodAndUri()), fn (string $key): bool => str_contains($key, 'register')))->toBe([]);
});
