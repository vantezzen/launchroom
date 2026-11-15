<?php

declare(strict_types=1);

namespace App\Listeners\Transfers;

use App\Events\Backups\BackupRunFinished;
use App\Jobs\Transfers\AdvanceImportsJob;

/**
 * A backup finished: an import waiting for its safety snapshot starts
 * right away instead of at the next tick (docs/14 §12.5).
 */
final class AdvanceImportsOnBackupFinished
{
    public function handle(BackupRunFinished $event): void
    {
        dispatch(new AdvanceImportsJob);
    }
}
