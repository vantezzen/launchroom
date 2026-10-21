<?php

declare(strict_types=1);

namespace App\Http\Auth;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * Re-authentication for interactive sessions (docs/09 R33): a password
 * confirmed in the last 15 minutes (stricter than the 3-hour window of other
 * sensitive actions), confirmed inline because a fetch can't follow the
 * confirm-password page. Failures share PasswordConfirmationThrottle's budget.
 */
final class SessionPasswordGate
{
    public static function recentlyConfirmed(Request $request): bool
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at', 0);

        return is_numeric($confirmedAt) && (time() - (int) $confirmedAt) < config()->integer('launchroom.sessions.password_window_seconds');
    }

    /**
     * Null when the password is (now) confirmed, else the 423 answer asking for it.
     */
    public static function check(Request $request, User $user, #[SensitiveParameter] ?string $password): ?JsonResponse
    {
        if (self::recentlyConfirmed($request)) {
            return null;
        }

        if ($password === null || $password === '') {
            return new JsonResponse(['message' => 'Confirm your password to open a session.', 'requiresPassword' => true], 423);
        }

        PasswordConfirmationThrottle::confirm($request, $user, $password);
        $request->session()->put('auth.password_confirmed_at', time());

        return null;
    }
}
