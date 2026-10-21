<?php

declare(strict_types=1);

namespace App\Console\Commands\Logs;

use App\Actions\Logs\PruneRuntimeLogs;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:logs:prune')]
#[Description('Delete runtime logs past their retention period or size cap (docs/08 §12)')]
final class PruneRuntimeLogsCommand extends Command
{
    public function handle(PruneRuntimeLogs $prune): int
    {
        $deleted = $prune->handle();
        $this->info($deleted === 0 ? 'Nothing to prune.' : "Deleted {$deleted} runtime log chunk(s).");

        return self::SUCCESS;
    }
}
