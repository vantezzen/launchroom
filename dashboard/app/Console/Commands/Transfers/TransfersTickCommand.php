<?php

declare(strict_types=1);

namespace App\Console\Commands\Transfers;

use App\Actions\Transfers\AdvanceImports;
use App\Actions\Transfers\SweepDatabasePreparations;
use App\Actions\Transfers\SyncTransferTasks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:transfers:tick')]
#[Description('Move data imports forward and mirror import/export task outcomes (docs/14 §12.5)')]
final class TransfersTickCommand extends Command
{
    public function handle(SyncTransferTasks $sync, AdvanceImports $advance, SweepDatabasePreparations $preparations): int
    {
        $sync->handle();
        $advance->handle();
        $preparations->handle();

        return self::SUCCESS;
    }
}
