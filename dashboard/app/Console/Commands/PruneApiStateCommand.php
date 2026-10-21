<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiIdempotencyKey;
use App\Models\ApiToken;
use App\Models\CliLoginRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily: expired CLI sign-in requests, idempotency keys past their window,
 * and tokens that expired more than 30 days ago (docs/16 §2.1, §4).
 */
#[Signature('launchroom:api:prune')]
#[Description('Delete expired CLI sign-ins, old idempotency keys and long-expired API tokens')]
final class PruneApiStateCommand extends Command
{
    public function handle(): int
    {
        $logins = CliLoginRequest::query()->where('expires_at', '<', now()->subDay())->delete();
        $keys = ApiIdempotencyKey::query()->where('created_at', '<', now()->subHours(config()->integer('api.idempotency_hours')))->delete();
        $tokens = ApiToken::query()->where('expires_at', '<', now()->subDays(30))->delete();

        $this->components->info(sprintf('Pruned %d sign-in requests, %d idempotency keys, %d expired tokens.', is_int($logins) ? $logins : 0, is_int($keys) ? $keys : 0, is_int($tokens) ? $tokens : 0));

        return self::SUCCESS;
    }
}
