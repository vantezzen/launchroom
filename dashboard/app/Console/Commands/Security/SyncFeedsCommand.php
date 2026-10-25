<?php

declare(strict_types=1);

namespace App\Console\Commands\Security;

use App\Jobs\Security\SyncFeedsJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:security:feeds')]
#[Description('Fetch CISA KEV and the release cycles of runtimes, engines and distributions from endoflife.date (docs/22 §4.3)')]
final class SyncFeedsCommand extends Command
{
    public function handle(): int
    {
        dispatch(new SyncFeedsJob);

        return self::SUCCESS;
    }
}
