<?php

declare(strict_types=1);

namespace App\Http\Errors;

use App\Data\Errors\ErrorPageData;
use App\Enums\Http\ErrorPageKind;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Routing\RouteFamily;
use App\Models\User;
use App\Queries\Errors\MissingResourceSuggestions;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Dashboard error pages (docs/08 §24): a 403, 404, 419, 429 or 5xx answer
 * to a page request becomes the Inertia page `errors/show`, inside the shell
 * when signed in. JSON requests and machine-facing routes keep their own
 * answers; with APP_DEBUG a 500 keeps Laravel's debug page.
 */
final class ErrorPageRenderer
{
    /** The longest path the page echoes back. */
    private const int MAX_PATH = 200;

    public static function register(Exceptions $exceptions): void
    {
        // The reference a 5xx page shows is the one its log entry carries.
        $exceptions->context(static fn (): array => ['reference' => ErrorReference::for(request())]);

        $exceptions->respond(static fn (Response $response, Throwable $e, Request $request): Response => self::respond($response, $request));
    }

    public static function respond(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();
        $kind = ErrorPageKind::forStatus($status);

        if (! $kind instanceof ErrorPageKind || ! self::rendersPage($request) || ($kind === ErrorPageKind::ServerError && config()->boolean('app.debug'))) {
            return $response;
        }

        try {
            return self::render($request, $status, $kind, $response);
        } catch (Throwable $failure) {
            // The page needs the database (shared props); without it, the static view.
            report($failure);

            return response()->view('edge-error', [
                'status' => $status,
                'title' => $status >= 500 ? 'Something went wrong' : 'This page can’t be shown',
                'text' => $status >= 500 ? 'launchroom ran into an error and logged it. Try again in a moment.' : 'Go back, or open launchroom’s home page.',
                'host' => null,
            ], $status);
        }
    }

    private static function rendersPage(Request $request): bool
    {
        return RouteFamily::of($request) === RouteFamily::Browser && ! $request->expectsJson();
    }

    private static function render(Request $request, int $status, ErrorPageKind $kind, Response $original): Response
    {
        self::prepareInertia($request);

        $user = $request->user();
        $suggestions = $kind === ErrorPageKind::NotFound && $user instanceof User
            ? resolve(MissingResourceSuggestions::class)->for($user, $request->path())
            : ['missing' => null, 'suggestions' => []];

        $data = new ErrorPageData(
            status: $status,
            kind: $kind,
            path: Str::limit('/'.ltrim($request->path(), '/'), self::MAX_PATH),
            missing: $suggestions['missing'],
            suggestions: $suggestions['suggestions'],
            reference: $kind === ErrorPageKind::ServerError ? ErrorReference::for($request) : null,
            retryAfter: self::retryAfter($original),
            reloadTo: $kind === ErrorPageKind::Expired ? self::placeOf($request) : null,
        );

        // A failed form post keeps the URL of the page the form was on.
        if (! $request->isMethodSafe()) {
            $place = self::placeOf($request);
            Inertia::resolveUrlUsing(static fn (): string => $place);
        }

        $response = Inertia::render('errors/show', ['error' => $data])->toResponse($request);
        $response->setStatusCode($status);

        if ($data->retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $data->retryAfter);
        }

        return $response;
    }

    /**
     * Errors thrown before HandleInertiaRequests ran (route bindings, CSRF,
     * maintenance mode) still get the shared props and the asset version.
     */
    private static function prepareInertia(Request $request): void
    {
        if (Inertia::getShared('auth') !== null) {
            return;
        }

        $middleware = resolve(HandleInertiaRequests::class);
        Inertia::version(static fn (): ?string => $middleware->version($request));
        Inertia::share($middleware->share($request));
    }

    /** The same-origin page the request came from, else this request's GET URL. */
    private static function placeOf(Request $request): string
    {
        $referer = $request->headers->get('referer');
        $origin = $request->getSchemeAndHttpHost();

        if (is_string($referer) && str_starts_with($referer, $origin.'/')) {
            return Str::after($referer, $origin);
        }

        return $request->isMethodSafe() ? $request->getRequestUri() : '/';
    }

    private static function retryAfter(Response $original): ?int
    {
        $value = $original->headers->get('Retry-After');

        return is_string($value) && ctype_digit($value) ? min((int) $value, 86_400) : null;
    }
}
