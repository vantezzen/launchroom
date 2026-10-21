<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DomainRuleViolation;
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
 *   launchroom went;
 * - launchroomd's endpoints, the move endpoints the new machine uses,
 *   health and the registry realm always pass: servers keep their state
 *   and keep reporting, and the hand-over can finish.
 */
final class RespectInstanceFreeze
{
    /** @var list<string> */
    private const array ALWAYS = ['daemon/*', 'move/*', 'up', 'registry/token', 'install/*', '.well-known/launchroom/*', 'auth/preview', 'build/*'];

    /** Writes that still work while frozen: signing in and out, aborting the hand-over. */
    private const array WRITES = ['login', 'logout', 'two-factor-challenge', 'settings/move/*', 'user/confirm-password', 'user/confirmed-password-status'];

    /**
     * Globally: the API, webhooks and every page once moved. In the web
     * group (`:web`, after the session started): refused form writes, so
     * the page shows the message where the action was.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $layer = 'global'): Response
    {
        if ($request->is(...self::ALWAYS) || ! InstanceFreeze::active()) {
            return $next($request);
        }

        $moved = InstanceFreeze::movedTo();
        $message = $moved === null
            ? 'launchroom is moving to a new server. Changes are paused for a few minutes.'
            : "launchroom moved to {$moved}.";

        if ($layer === 'web') {
            if (! $request->isMethodSafe() && ! $request->is(...self::WRITES)) {
                throw DomainRuleViolation::conflict($message);
            }

            return $next($request);
        }

        // The API and webhooks: changes wait (503 with Retry-After); once moved, everything points away.
        if (($request->is('api/*', 'webhooks/*', 'cli/*') && (! $request->isMethodSafe() || $moved !== null)) || ($moved !== null && $request->expectsJson())) {
            return new JsonResponse(['error' => ['code' => 'moving', 'message' => $message]], 503, ['Retry-After' => '120']);
        }

        if ($moved !== null) {
            return Inertia::render('moved', ['movedTo' => $moved])->toResponse($request);
        }

        return $next($request);
    }
}
