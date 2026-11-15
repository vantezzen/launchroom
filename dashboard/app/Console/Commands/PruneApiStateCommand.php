<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Api\PruneApiState;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:api:prune')]
#[Description('Delete expired CLI sign-ins, old idempotency keys and long-expired API tokens')]
final class PruneApiStateCommand extends Command
{
    public function handle(PruneApiState $prune): int
    {
        $pruned = $prune->handle();

        $this->components->info(sprintf('Pruned %d sign-in requests, %d idempotency keys, %d expired tokens.', $pruned->logins, $pruned->idempotencyKeys, $pruned->tokens));

        return self::SUCCESS;
    }
}
