<?php

declare(strict_types=1);

namespace App\Listeners\Moves;

use App\Events\Backups\BackupRunFinished;
use App\Events\Transfers\ExportRunFinished;
use App\Events\Transfers\ImportRunFinished;
use App\Jobs\Moves\AdvanceServerMovesJob;
use App\Models\Moves\ServerMove;

/**
 * A backup, export or import run finished: a server move waiting for it
 * continues right away (docs/14 §13.2).
 */
final class AdvanceMovesOnRunFinished
{
    public function handle(BackupRunFinished|ExportRunFinished|ImportRunFinished $event): void
    {
        if (ServerMove::query()->advancing()->exists()) {
            dispatch(new AdvanceServerMovesJob);
        }
    }
}
