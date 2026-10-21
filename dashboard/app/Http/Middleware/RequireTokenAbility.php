<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiAbility;
use App\Exceptions\MissingApiAbility;
use App\Models\ApiToken;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ability an API route requires (docs/16 §1.2), checked before the
 * controller: `RequireTokenAbility:deploy`. Without a token (no API
 * authentication) the request is unauthenticated, never let through.
 */
final class RequireTokenAbility
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $required = ApiAbility::from($ability);
        $user = $request->user();
        $token = $user instanceof User ? $user->currentApiToken() : null;

        if (! $token instanceof ApiToken) {
            throw new AuthenticationException;
        }

        if (! $token->hasAbility($required)) {
            throw new MissingApiAbility($required);
        }

        return $next($request);
    }
}
