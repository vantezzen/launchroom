<?php

declare(strict_types=1);

namespace App\Http\Controllers\Previews;

use App\Enums\EnvironmentKind;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Previews\PreviewSettings;
use App\Models\User;
use App\Services\Previews\PreviewAccess;
use App\Services\Previews\PreviewAddress;
use App\Services\Previews\PreviewUrls;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Preview protection (docs/06 §5, docs/09 R20a; flow in
 * {@see PreviewAccess}).
 *
 * `GET /auth/preview` is Caddy's forward-auth endpoint: no session, no CSRF,
 * it reads the original request ({@see PreviewAddress}, `X-Forwarded-Method`,
 * set by the edge Caddy, which overwrites client-supplied values) and the
 * preview host's own cookie. 2xx lets the request through; other answers go
 * to the browser as they are (redirects with Set-Cookie for the preview host).
 * Calling it directly gains nothing: it only ever sets cookies for the host
 * it names, and only from valid tokens.
 *
 * `GET /auth/preview/start` runs on the dashboard origin with the normal
 * session: members of the preview's team get a single-use exchange token.
 */
final class PreviewAuthController extends Controller
{
    public function check(Request $request, PreviewUrls $urls, PreviewAccess $access): Response|RedirectResponse
    {
        $address = PreviewAddress::parse((string) $request->headers->get('X-Forwarded-Host', ''), (string) $request->headers->get('X-Forwarded-Uri', '/'));
        $host = $address->host;
        $method = strtoupper((string) $request->headers->get('X-Forwarded-Method', 'GET'));

        $target = $host === null ? null : $urls->resolve($host);

        if ($target === null) {
            return $this->denied($request, 403, 'launchroom: this preview no longer exists.', 'This preview doesn’t exist', 'It was removed, or the address is wrong.', $host);
        }

        $environment = $target['environment'];
        $environment->loadMissing('project');

        if ($environment->kind !== EnvironmentKind::Preview) {
            return $this->text(200, 'ok');
        }

        $settings = PreviewSettings::forProject($environment->project);

        if (! $settings->protection) {
            return $this->text(200, 'ok');
        }

        $bypass = (string) $request->headers->get(config()->string('launchroom.previews.bypass_header'), '');

        if ($bypass !== '' && $access->bypassGrants($bypass, $environment->project, $settings)) {
            return $this->text(200, 'ok');
        }

        if ($address->token !== null) {
            $cookie = $access->exchange($address->token, $environment, (string) $host);

            if ($cookie === null) {
                return $this->denied($request, 403, 'launchroom: this preview link is invalid, was revoked or has expired. Ask for a new one.', 'This link no longer works', 'It is invalid, was revoked or has expired. Ask for a new one.', $host);
            }

            return $this->noStore(new RedirectResponse($address->origin().$address->path, 302))
                ->withCookie(new Cookie(
                    config()->string('launchroom.previews.cookie'),
                    $cookie['value'],
                    $cookie['expires'],
                    '/',
                    null,
                    true,
                    true,
                    false,
                    Cookie::SAMESITE_LAX,
                ));
        }

        $cookie = (string) $request->cookies->get(config()->string('launchroom.previews.cookie'), '');

        if ($cookie !== '' && $access->cookieGrants($cookie, $environment, (string) $host)) {
            return $this->text(200, 'ok');
        }

        if (! in_array($method, ['GET', 'HEAD'], true)) {
            return $this->denied($request, 401, 'launchroom: sign in to view this preview.', 'Sign in to view this preview', 'This preview is protected. Reload the page to sign in.', $host);
        }

        $start = rtrim(config()->string('app.url'), '/').'/auth/preview/start?'.http_build_query([
            'host' => $address->hostWithPort(),
            'path' => $address->path,
        ]);

        return $this->noStore(new RedirectResponse($start, 302));
    }

    public function start(Request $request, #[CurrentUser] User $user, PreviewUrls $urls, PreviewAccess $access): SymfonyResponse
    {
        $address = PreviewAddress::parse($request->string('host')->toString(), $request->string('path')->toString());
        $target = $address->host === null ? null : $urls->resolve($address->host);
        $environment = $target['environment'] ?? null;

        // Only ever redirects to a routed preview host the user may view, on
        // the edges' public HTTPS port (no open redirect, no token sent to
        // another port of the host): unknown hosts 404, other teams' previews 403.
        $publicPort = config()->integer('launchroom.previews.https_port');
        abort_unless($environment instanceof Environment && $environment->kind === EnvironmentKind::Preview && ($address->port ?? 443) === $publicPort, 404);
        Gate::authorize('view', $environment);

        $token = $access->mintExchange($environment, (string) $address->host, $user);

        // After an Inertia login form the intended URL is fetched by XHR,
        // which can't follow a cross-origin redirect: Inertia::location makes
        // the browser navigate instead (409 + X-Inertia-Location).
        $response = Inertia::location($address->urlWithToken($token));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * A denial the edge passes to the visitor unchanged: an HTML page in the
     * edge's error-page style for browsers, the plain line for everyone else.
     */
    private function denied(Request $request, int $status, string $plain, string $title, string $text, ?string $host): Response
    {
        if (! str_contains((string) $request->headers->get('Accept', ''), 'text/html')) {
            return $this->text($status, $plain);
        }

        return $this->noStore(new Response(
            view('edge-error', ['status' => $status, 'title' => $title, 'text' => $text, 'host' => $host])->render(),
            $status,
            ['Content-Type' => 'text/html; charset=utf-8'],
        ));
    }

    private function text(int $status, string $body): Response
    {
        return $this->noStore(new Response($body."\n", $status, ['Content-Type' => 'text/plain; charset=utf-8']));
    }

    /**
     * @template T of Response|RedirectResponse
     *
     * @param  T  $response
     * @return T
     */
    private function noStore(Response|RedirectResponse $response): Response|RedirectResponse
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
