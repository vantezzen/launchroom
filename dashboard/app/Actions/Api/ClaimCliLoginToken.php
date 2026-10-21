<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Data\Api\ApiTeamData;
use App\Data\Api\ApiUserData;
use App\Data\Api\CliTokenData;
use App\Enums\ApiAbility;
use App\Enums\CliLoginStatus;
use App\Exceptions\ApiError;
use App\Models\CliLoginRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * The CLI's poll (docs/16 §4): pending → `authorization_pending`, denied →
 * `access_denied`, expired, unknown or already picked up → `expired_token`;
 * approved → the token, exactly once (the ciphertext is wiped in the same
 * transaction).
 */
final class ClaimCliLoginToken
{
    public function handle(#[SensitiveParameter] string $deviceCode): CliTokenData
    {
        return DB::transaction(static function () use ($deviceCode): CliTokenData {
            $request = CliLoginRequest::query()
                ->where('device_code_hash', hash('sha256', $deviceCode))
                ->with(['user', 'team', 'token'])
                ->lockForUpdate()
                ->first();

            $expired = new ApiError('expired_token', 'This sign-in expired. Run launchroom login again.', 410);

            if ($request === null || $request->status === CliLoginStatus::Consumed) {
                throw $expired;
            }

            if ($request->status === CliLoginStatus::Denied) {
                throw new ApiError('access_denied', 'The sign-in was denied in the dashboard.', 403);
            }

            if ($request->status === CliLoginStatus::Pending) {
                throw $request->isExpired() ? $expired : new ApiError('authorization_pending', 'Waiting for you to approve the sign-in in the browser.', 428);
            }

            $token = $request->token;
            $user = $request->user;
            $team = $request->team;

            if ($request->token_ciphertext === null || $token === null || $user === null || $team === null || ! $token->isUsable()) {
                throw $expired;
            }

            $plainText = Crypt::decryptString($request->token_ciphertext);
            $request->transitionTo(CliLoginStatus::Consumed, ['token_ciphertext' => null, 'consumed_at' => now()]);

            return new CliTokenData(
                token: $plainText,
                expiresAt: (string) $token->expires_at?->toIso8601String(),
                team: new ApiTeamData($team->public_id, $team->name, $user->roleIn($team)),
                abilities: array_values(array_filter(array_map(ApiAbility::tryFrom(...), $token->abilities))),
                user: new ApiUserData($user->name, $user->email),
            );
        });
    }
}
