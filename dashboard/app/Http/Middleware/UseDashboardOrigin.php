<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Instance\DashboardOrigin;
use App\Support\Origin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the canonical dashboard origin to the request (DashboardOrigin)
 * and keeps earlier origins working after Settings → Dashboard domain
 * switched it (docs/05 §4.1, D43):
 *
 * - launchroomd's endpoints (daemon API, preview forward auth, the registry
 *   realm, the domain test request, `/up`) are answered on every earlier
 *   origin for good: it is every server's fallback URL;
 * - the public API, the CLI's endpoints, GitHub webhooks and the installer
 *   script are answered there during the transition window
 *   (`launchroom.dashboard.transition_days`);
 * - everything else, and the rest once the window is over, gets a 308 to the
 *   same path on the canonical origin (`no-store`, so a later "Switch back"
 *   never meets a cached redirect loop).
 *
 * A new domain that is still being verified only answers the test request
 * and `/up`; browsers there go to the canonical origin. Hosts that are none
 * of these (loopback for the control plane's own launchroomd, dev hostnames)
 * pass unchanged. Reverb (`/app/*`) and Headscale's paths never reach PHP:
 * FrankenPHP proxies them for every host.
 */
final readonly class UseDashboardOrigin
{
    /** @var list<string> */
    private const array DAEMON_PATHS = ['daemon/*', 'move/*', 'auth/preview', 'registry/token', 'up', '.well-known/launchroom/*'];

    /** @var list<string> */
    private const array TRANSITION_PATHS = ['api/*', 'cli/*', 'webhooks/*', 'install/*'];

    public function __construct(
        private DashboardOrigin $origin,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->origin->apply();

        $host = strtolower($request->getHost());

        if ($host === $this->origin->canonicalHost() || Origin::isLoopback($host)) {
            return $next($request);
        }

        if ($host === $this->origin->pendingHost()) {
            return $request->is('.well-known/launchroom/*', 'up') ? $next($request) : $this->redirect($request);
        }

        foreach ($this->origin->legacy() as $legacy) {
            if ($legacy['host'] !== $host) {
                continue;
            }

            if ($request->is(...self::DAEMON_PATHS)
                || ($legacy['until']->isFuture() && $request->is(...self::TRANSITION_PATHS))) {
                return $next($request);
            }

            return $this->redirect($request);
        }

        return $next($request);
    }

    private function redirect(Request $request): Response
    {
        return redirect()->away($this->origin->canonical().$request->getRequestUri(), 308)
            ->withHeaders(['Cache-Control' => 'no-store']);
    }
}
