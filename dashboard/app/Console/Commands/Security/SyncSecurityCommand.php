<?php

declare(strict_types=1);

namespace App\Console\Commands\Security;

use App\Jobs\Security\SyncAdvisoriesJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:security:sync {--now : Run in this process instead of the queue}')]
#[Description('Ask osv.dev about every inventoried package not checked in the last 6 hours (docs/22 §4.3)')]
final class SyncSecurityCommand extends Command
{
    public function handle(): int
    {
        if ($this->option('now') === true) {
            dispatch_sync(new SyncAdvisoriesJob);

            return self::SUCCESS;
        }

        // Jittered by up to 30 minutes, so instances don't all ask at once.
        dispatch(new SyncAdvisoriesJob)->delay(now()->addSeconds(random_int(0, 1800)));

        return self::SUCCESS;
    }
}
