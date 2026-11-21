<?php

declare(strict_types=1);

namespace App\Listeners\Transfers;

use App\Enums\Transfers\CopyRunStatus;
use App\Events\Transfers\ExportRunFinished;
use App\Events\Transfers\ImportRunFinished;
use App\Jobs\Transfers\AdvanceCopiesJob;
use App\Models\Transfers\DataCopyRun;

/**
 * An export or import run finished: a copy between environments waiting
 * for it continues right away (docs/14 §14.2).
 */
final class AdvanceCopiesOnRunFinished
{
    public function handle(ExportRunFinished|ImportRunFinished $event): void
    {
        if (DataCopyRun::query()->whereIn('status', CopyRunStatus::nonTerminal())->exists()) {
            dispatch(new AdvanceCopiesJob);
        }
    }
}
