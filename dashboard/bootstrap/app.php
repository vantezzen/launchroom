<?php

declare(strict_types=1);

use App\Exceptions\DomainRuleViolation;
use App\Http\Api\ApiExceptionRenderer;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RespectInstanceFreeze;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleRequestsPerRoute;
use App\Http\Middleware\UseDashboardOrigin;
use App\Support\SensitiveInput;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: static function (): void {
            // Daemon API: no web group (no session/cookies/CSRF); its own
            // signature middleware inside (docs/03 §4.1).
            Route::prefix('daemon/v1')->group(__DIR__.'/../routes/daemon.php');

            // Public API v1 (docs/16 §2): bearer tokens only, no web group.
            Route::prefix('api/v1')->group(__DIR__.'/../routes/api.php');

            // Settings → API tokens, CLI sign-in approval, API reference (web group inside).
            Route::group([], __DIR__.'/../routes/api-tokens.php');

            // GitHub integration: dashboard pages (web group inside) and the
            // public, signature-verified webhook (no web group).
            Route::group([], __DIR__.'/../routes/git.php');

            // Installer downloads (/install/server.sh): public, no web group.
            Route::group([], __DIR__.'/../routes/install.php');

            // The new machine of a control-plane move (docs/14 §13.4): token
            // authenticated, signed answers, no web group.
            Route::prefix('move/v1')->group(__DIR__.'/../routes/moves.php');

            // Projects, canvas and service panel (web group inside).
            Route::group([], __DIR__.'/../routes/projects.php');
            Route::group([], __DIR__.'/../routes/blueprints.php');

            // Deployments, logs, variables, staged changes, ⌘K (web group inside).
            Route::group([], __DIR__.'/../routes/deployments.php');

            // Previews: pages (web group inside) and the forward-auth endpoint
            // edge Caddies ask for protected previews (no web group).
            Route::group([], __DIR__.'/../routes/previews.php');

            // Environments, Compare & promote, project activity (web group inside).
            Route::group([], __DIR__.'/../routes/environments.php');
        },
    )
    // Channel authorization endpoint requires a session; each channel callback
    // in routes/channels.php additionally authorizes (docs/09-security.md R7).
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // In production the edge Caddy (run by the local daemon) proxies to the
        // dashboard, whose port is published on 127.0.0.1 only, so requests
        // arrive from the Docker bridge gateway. Trust X-Forwarded-* from
        // private ranges only, for the client IP (rate limits, audit) and the
        // https scheme (docs/11 §2, docs/05 §4).
        $middleware->trustProxies(at: ['127.0.0.1/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1', 'fc00::/7']);

        // `throttle:N,M` counts per user (or IP) and route, not per user alone.
        $middleware->alias(['throttle' => ThrottleRequestsPerRoute::class]);

        // CSP (nonce), HSTS (production), nosniff, frame and referrer policy (docs/09 R26).
        $middleware->append(SecurityHeaders::class);

        // The canonical dashboard origin and earlier ones after a domain
        // switch (docs/05 §4.1): app.url, passkeys, cookies, redirects.
        $middleware->append(UseDashboardOrigin::class);

        // Writes paused during a control-plane hand-over, then "moved" (docs/14 §13.4).
        $middleware->append(RespectInstanceFreeze::class);

        $middleware->web(append: [
            RespectInstanceFreeze::class.':web',
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // One error format for /api/v1 (docs/16 §2.1); registered first so it
        // answers before the web renderers below.
        ApiExceptionRenderer::register($exceptions);

        // Secrets are never flashed to the session as old input (docs/09 R12).
        $exceptions->dontFlash(SensitiveInput::KEYS);

        // Laravel's dontFlash matches exact keys only; also drop anything
        // that looks like a password, token or secret, at any depth.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*', 'daemon/*', 'move/*') || $request->expectsJson() || $e->response !== null) {
                return null;
            }

            $input = $request->input();

            return redirect($e->redirectTo ?? url()->previous())
                ->withInput(SensitiveInput::except(is_array($input) ? $input : []))
                ->withErrors($e->errors(), $request->string('_error_bag', $e->errorBag)->toString());
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*', 'daemon/*', 'move/*') || $request->expectsJson(),
        );

        // Use-case refusals carry user-facing messages (docs/10 §2.2 rule 10).
        $exceptions->render(function (DomainRuleViolation $e, Request $request) {
            if ($request->is('daemon/*', 'move/*')) {
                return new JsonResponse(['error' => $e->getMessage()], $e->status);
            }

            if ($request->expectsJson()) {
                return new JsonResponse(['message' => $e->getMessage(), 'errors' => [$e->field => [$e->getMessage()]]], $e->status);
            }

            $input = $request->input();

            return back()->withErrors([$e->field => $e->getMessage()])->withInput(SensitiveInput::except(is_array($input) ? $input : []));
        });
    })->create();
