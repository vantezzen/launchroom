<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Api\CreateApiToken;
use App\Data\Audit\AuditActor;
use App\Enums\ApiAbility;
use App\Enums\ApiTokenClient;
use App\Enums\Permission;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public API test helpers (docs/16): tokens and requests that never reuse a
 * guard's cached user between calls.
 */
final class Api
{
    /**
     * A token through the real action (role-checked).
     *
     * @param  list<ApiAbility>|null  $abilities
     */
    public static function token(User $user, Team $team, ?array $abilities = null, int $days = 30): string
    {
        $role = $user->roleIn($team);
        $abilities ??= array_values(array_filter(ApiAbility::cases(), static fn (ApiAbility $ability): bool => $role?->can($ability->grants() ?? Permission::View) ?? false));

        return resolve(CreateApiToken::class)
            ->handle($user, $team, 'test', $abilities, $days, ApiTokenClient::Dashboard, AuditActor::system())
            ->plainText;
    }

    /**
     * A token row written directly (any abilities, any expiry), for tests of
     * the guards themselves.
     *
     * @param  list<string>  $abilities
     */
    public static function rawToken(User $user, Team $team, array $abilities, mixed $expiresAt = 'default'): string
    {
        $secret = 'lr_pat_'.Str::random(40);
        $token = new ApiToken;
        $token->forceFill([
            'name' => 'raw',
            'token' => hash('sha256', $secret),
            'abilities' => $abilities,
            'expires_at' => $expiresAt === 'default' ? now()->addDay() : $expiresAt,
            'team_id' => $team->id,
            'client' => ApiTokenClient::Dashboard,
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->id,
        ])->save();

        return $token->id.'|'.$secret;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public static function call(string $method, string $uri, ?string $token, array $data = [], array $headers = []): TestResponse
    {
        resolve('auth')->forgetGuards();
        $test = test();
        $test->flushHeaders();

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $test->json($method, '/api/v1/'.ltrim($uri, '/'), $data, $headers);
    }

    /**
     * @return TestResponse<Response>
     */
    public static function get(string $uri, ?string $token): TestResponse
    {
        return self::call('GET', $uri, $token);
    }
}
