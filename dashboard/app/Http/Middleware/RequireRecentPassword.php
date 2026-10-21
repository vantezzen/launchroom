<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Password confirmation for sensitive mutations (docs/09 R10: add server,
 * change roles, invitations). Unlike Laravel's RequirePassword it returns the
 * user to the page they submitted from after confirming, because a POST
 * can't be replayed through a redirect; only same-origin pages count.
 */
final class RequireRecentPassword
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at', 0);
        $timeout = config()->integer('auth.password_timeout', 10800);

        if (is_numeric($confirmedAt) && (time() - (int) $confirmedAt) < $timeout) {
            return $next($request);
        }

        $request->session()->put('url.intended', self::intendedUrl($request));

        return to_route('password.confirm');
    }

    /**
     * Where to go after confirming: the page itself for GET requests, else
     * the page the form was submitted from, but only when it is on this
     * origin. `url()->previous()` is the raw Referer header, which an
     * attacker page controls (review L5: open redirect).
     */
    public static function intendedUrl(Request $request): string
    {
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return $request->fullUrl();
        }

        $previous = url()->previous();
        $fallback = url('/');

        return self::sameOrigin($previous, $request) ? $previous : $fallback;
    }

    private static function sameOrigin(string $url, Request $request): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);

        return strtolower($parts['scheme']) === $request->getScheme()
            && strtolower($parts['host']) === strtolower($request->getHost())
            && $port === $request->getPort();
    }
}
