<?php

declare(strict_types=1);

namespace App\Http\Routing;

use Illuminate\Http\Request;

/**
 * Who a request is for, by path: the one classification the instance
 * freeze (RespectInstanceFreeze), the dashboard-origin redirects
 * (UseDashboardOrigin) and the error renderers (bootstrap/app.php) ask.
 * A new machine-facing prefix registered in bootstrap/app.php's withRouting
 * gets its family here.
 */
enum RouteFamily
{
    /** launchroomd's signed API (`daemon/v1`) and the new machine of a control-plane move (`move/v1`). */
    case Daemon;

    /** Called by servers on launchroomd's behalf: edge Caddy's preview forward auth, Docker's registry token realm. */
    case Edge;

    /** Health (`/up`) and the dashboard-domain probe (docs/05 §4.1). */
    case Probe;

    /** The installer script servers download. */
    case Installer;

    /** The public API (`api/v1`, docs/16). */
    case Api;

    /** The CLI's sign-in approval pages. */
    case Cli;

    /** Git provider webhooks. */
    case Webhook;

    /**
     * Built frontend assets. They reach PHP only when no static file server
     * answers them first; the "moved" page still needs its own scripts.
     */
    case Asset;

    /** Dashboard pages and their JSON endpoints. */
    case Browser;

    /** @var array<string, list<string>> */
    private const array PATHS = [
        'Daemon' => ['daemon/*', 'move/*'],
        'Edge' => ['auth/preview', 'registry/token'],
        'Probe' => ['up', 'up/*', '.well-known/launchroom/*'],
        'Installer' => ['install/*'],
        'Api' => ['api/*'],
        'Cli' => ['cli/*'],
        'Webhook' => ['webhooks/*'],
        'Asset' => ['build/*'],
    ];

    public static function of(Request $request): self
    {
        foreach (self::cases() as $family) {
            if ($family !== self::Browser && $request->is(...self::PATHS[$family->name])) {
                return $family;
            }
        }

        return self::Browser;
    }

    /**
     * The route pattern of the dashboard's fallback route: every path except
     * those of the machine-facing families, which stay unmatched.
     */
    public static function browserFallbackPattern(): string
    {
        $excluded = [];

        foreach (self::PATHS as $patterns) {
            foreach ($patterns as $pattern) {
                $excluded[] = str_ends_with($pattern, '/*')
                    ? preg_quote(substr($pattern, 0, -1), '#')
                    : preg_quote($pattern, '#').'$';
            }
        }

        return '(?!(?:'.implode('|', $excluded).')).+';
    }

    /**
     * Never paused by a control-plane hand-over: servers keep their state and
     * keep reporting, and the hand-over can finish (docs/14 §13.4).
     */
    public function passesFreeze(): bool
    {
        return in_array($this, [self::Daemon, self::Edge, self::Probe, self::Installer, self::Asset], true);
    }

    /**
     * Paused by a hand-over with a JSON 503 instead of a page.
     */
    public function pausesWithJson(): bool
    {
        return in_array($this, [self::Api, self::Cli, self::Webhook], true);
    }

    /**
     * Answered on every earlier dashboard origin for good: every server's
     * fallback URL (docs/05 §4.1, D43).
     */
    public function answersOnEveryOrigin(): bool
    {
        return in_array($this, [self::Daemon, self::Edge, self::Probe], true);
    }

    /**
     * Answered on an earlier origin during the transition window
     * (`launchroom.dashboard.transition_days`).
     */
    public function answersDuringOriginTransition(): bool
    {
        return in_array($this, [self::Api, self::Cli, self::Webhook, self::Installer], true);
    }

    /**
     * Answered on a new dashboard domain that is still being verified.
     */
    public function answersOnPendingOrigin(): bool
    {
        return $this === self::Probe;
    }

    /**
     * Errors are always JSON, never a redirect with flashed input.
     */
    public function rendersJson(): bool
    {
        return in_array($this, [self::Api, self::Daemon], true);
    }
}
