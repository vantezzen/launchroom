<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DomainRuleViolation;
use App\Http\Routing\RouteFamily;
use App\Services\Moves\InstanceFreeze;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The write freeze of a control-plane hand-over (docs/14 §13.4):
 *
 * - while frozen, changes are refused (web forms get the message, the API
 *   and webhooks answer 503) and pages stay readable;
 * - once the new dashboard took over (`moved_to`), every page says where
 *   launchroom went, except Settings → Move launchroom (instance owners
 *   only, after signing in), which shows the DNS records to change;
 * - what servers and the new machine call always passes
 *   (RouteFamily::passesFreeze()).
 */
final class RespectInstanceFreeze
{
    /** Writes that still work while frozen: signing in and out, aborting the hand-over. */
    private const array WRITES = ['login', 'logout', 'two-factor-challenge', 'passkeys/login', 'passkeys/login/*', 'settings/move/*', 'user/confirm-password', 'user/confirmed-password-status'];

    /**
     * Once moved, the pages still answered (in the web group, so for a
     * signed-in instance owner only): Settings → Move launchroom with the
     * DNS switch, and signing in to reach it. Every other page says where
     * launchroom went.
     */
    private const array MOVED_PAGES = ['settings/move', 'login', 'logout', 'two-factor-challenge', 'passkeys/login', 'passkeys/login/*'];

    /** Once moved, the only writes: signing in and out. */
    private const array MOVED_WRITES = ['login', 'logout', 'two-factor-challenge', 'passkeys/login', 'passkeys/login/*'];

    /**
     * Globally: the API, webhooks and every page once moved. In the web
     * group (`:web`, after the session started): refused form writes, so
     * the page shows the message where the action was.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $layer = 'global'): Response
    {
        $family = RouteFamily::of($request);

        if ($family->passesFreeze() || ! InstanceFreeze::active()) {
            return $next($request);
        }

        $moved = InstanceFreeze::movedTo();
        $message = $moved === null
            ? 'launchroom is moving to a new server. Changes are paused for a few minutes.'
            : "launchroom moved to {$moved}.";

        if ($layer === 'web') {
            if (! $request->isMethodSafe() && ! $request->is(...($moved === null ? self::WRITES : self::MOVED_WRITES))) {
                throw DomainRuleViolation::conflict($message);
            }

            return $next($request);
        }

        if ($moved !== null && $family === RouteFamily::Browser && $request->is(...self::MOVED_PAGES)) {
            return $next($request);
        }

        // The API and webhooks: changes wait (503 with Retry-After); once moved, everything points away.
        if (($family->pausesWithJson() && (! $request->isMethodSafe() || $moved !== null)) || ($moved !== null && $request->expectsJson())) {
            return new JsonResponse(['error' => ['code' => 'moving', 'message' => $message]], 503, ['Retry-After' => '120']);
        }

        if ($moved !== null) {
            return Inertia::render('moved', ['movedTo' => $moved, 'summaryHref' => '/settings/move'])->toResponse($request);
        }

        return $next($request);
    }
}
