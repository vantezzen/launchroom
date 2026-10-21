<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Data\Instance\RealtimeConfigData;
use App\Services\Git\AvatarOrigins;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for every dashboard response (docs/09 R26):
 *
 * - a nonce-based Content-Security-Policy: scripts only with this request's
 *   nonce (Vite tags get it via `Vite::useCspNonce()`, inline scripts in
 *   app.blade.php via `Vite::cspNonce()`) plus `'strict-dynamic'`, so the
 *   modules they import are trusted too; no plugins, no framing
 *   (`frame-ancestors 'none'`), `base-uri 'self'`; `connect-src` allows the
 *   page's own origin and the Reverb WebSocket origin. Styles allow
 *   `'unsafe-inline'` (component libraries set inline `style` attributes;
 *   style injection is not script execution). Forms may post to https
 *   origins: the GitHub App manifest flow posts to github.com or a GHE host.
 *   In local development the Vite dev server (`public/hot`) and its HMR
 *   socket are allowed as well.
 * - HSTS in production over https only (one year, no preload, no
 *   includeSubDomains: the dashboard host's subdomains are not ours to pin).
 * - nosniff, `X-Frame-Options: DENY` (older browsers), a strict referrer
 *   policy, a Permissions-Policy denying powerful features and COOP.
 *
 * The image's Caddyfile sets the static subset too, for responses that never
 * reach PHP (static files).
 */
final class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->policy($request, $nonce));
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (app()->isProduction() && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    public function policy(Request $request, string $nonce): string
    {
        $script = ["'self'", "'nonce-{$nonce}'", "'strict-dynamic'"];
        $style = ["'self'", "'unsafe-inline'"];
        $font = ["'self'", 'data:'];
        $connect = ["'self'", ...$this->realtimeOrigins($request)];

        $hot = $this->viteDevServer();

        if ($hot !== null) {
            $script[] = $hot['http'];
            $style[] = $hot['http'];
            $font[] = $hot['http'];
            $connect[] = $hot['http'];
            $connect[] = $hot['ws'];
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => $script,
            'style-src' => $style,
            // Commit author avatars (GitHubAvatar / ProviderAvatar build every such URL, docs/19 §7).
            'img-src' => ["'self'", 'data:', 'blob:', ...AvatarOrigins::all()],
            'font-src' => $font,
            'connect-src' => array_values(array_unique($connect)),
            'worker-src' => ["'self'", 'blob:'],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'frame-ancestors' => ["'none'"],
            'form-action' => ["'self'", 'https:'],
        ];

        return implode('; ', array_map(
            static fn (string $name, array $sources): string => $name.' '.implode(' ', $sources),
            array_keys($directives),
            $directives,
        ));
    }

    /**
     * The WebSocket origin(s) the browser uses for Reverb: the configured
     * public host/port/scheme, else the page's own origin (production
     * proxies /app/* to Reverb). In local development Reverb listens on its
     * own localhost port (VITE_REVERB_* are build-time values PHP can't see).
     *
     * @return list<string>
     */
    private function realtimeOrigins(Request $request): array
    {
        $config = RealtimeConfigData::current();
        $secure = $config?->scheme !== null ? $config->scheme === 'https' : $request->isSecure();
        $host = $config->host ?? $request->getHost();
        $port = $config->port ?? $request->getPort();
        $default = $secure ? 443 : 80;
        $authority = $port === $default ? $host : "{$host}:{$port}";
        $origins = [($secure ? 'wss://' : 'ws://').$authority];

        if (app()->environment('local')) {
            $origins[] = 'ws://localhost:*';
            $origins[] = 'ws://127.0.0.1:*';
        }

        return $origins;
    }

    /**
     * The Vite dev server origin while `npm run dev` is running (local only).
     *
     * @return array{http: string, ws: string}|null
     */
    private function viteDevServer(): ?array
    {
        if (! app()->environment('local') || ! Vite::isRunningHot()) {
            return null;
        }

        $hotFile = Vite::hotFile();
        $url = File::isFile($hotFile) ? File::get($hotFile) : false;
        $parts = is_string($url) ? parse_url(trim($url)) : false;

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || preg_match('/^[a-z0-9.-]+$/i', $parts['host']) !== 1) {
            return null;
        }

        $authority = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return [
            'http' => ($parts['scheme'] === 'https' ? 'https://' : 'http://').$authority,
            'ws' => ($parts['scheme'] === 'https' ? 'wss://' : 'ws://').$authority,
        ];
    }
}
