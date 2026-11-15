<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Data\Api\PrunedApiState;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiToken;
use App\Models\CliLoginRequest;

/**
 * Expired CLI sign-in requests, idempotency keys past their window and
 * long-expired tokens (docs/16 §2.1, §4).
 */
final class PruneApiState
{
    public function handle(): PrunedApiState
    {
        $logins = CliLoginRequest::query()->where('expires_at', '<', now()->subHours(config()->integer('api.cli_login.retention_hours')))->delete();
        $keys = ApiIdempotencyKey::query()->where('created_at', '<', now()->subHours(config()->integer('api.idempotency_hours')))->delete();
        $tokens = ApiToken::query()->where('expires_at', '<', now()->subDays(config()->integer('api.expired_token_retention_days')))->delete();

        return new PrunedApiState(is_int($logins) ? $logins : 0, is_int($keys) ? $keys : 0, is_int($tokens) ? $tokens : 0);
    }
}
