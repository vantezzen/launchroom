<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\PublicId;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\Sanctum;

/**
 * Public API v1 (docs/16): team-bound tokens, fail-closed expiry, last-used
 * IP and the API's rate limits.
 */
final class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        // Fail closed: a token without an expiry or a team is never valid
        // (docs/09 R11), whatever Sanctum's own checks say.
        Sanctum::authenticateAccessTokensUsing(static fn (mixed $token, bool $valid): bool => $valid
            && $token instanceof ApiToken
            && $token->isUsable()
            && $token->tokenable_type === (new User)->getMorphClass());

        // Sanctum saves last_used_at right after this event; the IP rides along.
        Event::listen(TokenAuthenticated::class, function (TokenAuthenticated $event): void {
            if ($event->token instanceof ApiToken) {
                $event->token->last_used_ip = $this->app->make(Request::class)->ip();
            }
        });

        // Settings → API tokens: only the signed-in user's own tokens resolve.
        Route::bind('apiToken', static function (string $value): ApiToken {
            $user = Auth::user();

            if (! $user instanceof User || ! PublicId::isValid($value, ApiToken::publicIdPrefix())) {
                throw (new ModelNotFoundException)->setModel(ApiToken::class);
            }

            return ApiToken::query()->ownedBy($user)->where('public_id', $value)->firstOrFail();
        });

        $limits = static fn (string $key): int => config()->integer('api.rate_limits.'.$key);
        $byToken = static function (Request $request): string {
            $user = $request->user();
            $token = $user instanceof User ? $user->currentApiToken() : null;

            return $token instanceof ApiToken ? 'token:'.$token->id : 'ip:'.$request->ip();
        };

        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute($limits('requests_per_minute'))->by($byToken($request)));
        RateLimiter::for('api-write', static fn (Request $request): Limit => Limit::perMinute($limits('writes_per_minute'))->by($byToken($request)));
        RateLimiter::for('api-cli-start', static fn (Request $request): Limit => Limit::perMinute($limits('cli_starts_per_minute'))->by((string) $request->ip()));
        RateLimiter::for('api-cli-poll', static fn (Request $request): Limit => Limit::perMinute($limits('cli_polls_per_minute'))->by((string) $request->ip()));
    }
}
