<?php

declare(strict_types=1);

namespace App\Http\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * The legacy confirm-password endpoint limits failed attempts per user and
 * client IP (review L4), so a hijacked session can't use it as a password oracle. Lockouts are
 * logged (no password, no value).
 */
final class PasswordConfirmationThrottle
{
    public const int MAX_ATTEMPTS = 5;

    public const int DECAY_SECONDS = 60;

    /**
     * Checks $password for $user; on failure counts an attempt and throws a
     * validation error on `password`.
     *
     * @throws ValidationException
     */
    public static function confirm(Request $request, User $user, #[SensitiveParameter] ?string $password): void
    {
        $keys = self::keys($request, $user);

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                throw ValidationException::withMessages([
                    'password' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
                ])->status(429);
            }
        }

        if (is_string($password) && $password !== '' && Hash::check($password, $user->getAuthPassword())) {
            RateLimiter::clear($keys[0]);

            return;
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                Log::warning('Password confirmation locked out after repeated failures.', [
                    'user' => $user->id,
                    'ip' => $request->ip(),
                    'scope' => str_contains($key, '|user:') ? 'user' : 'ip',
                ]);
            }
        }

        throw ValidationException::withMessages(['password' => __('auth.password')]);
    }

    /**
     * @return array{string, string} [per user, per IP]
     */
    private static function keys(Request $request, User $user): array
    {
        return ['password-confirmation|user:'.$user->id, 'password-confirmation|ip:'.$request->ip()];
    }
}
