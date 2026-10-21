<?php

declare(strict_types=1);

namespace App\Console\Commands\Alerts;

use App\Actions\Alerts\PruneAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:alerts:prune')]
#[Description('Delete alert deliveries older than 90 days and resolved alerts older than a year')]
final class PruneAlertsCommand extends Command
{
    public function handle(PruneAlerts $prune): int
    {
        $result = $prune->handle();

        $this->info("Deleted {$result['deliveries']} deliveries and {$result['alerts']} resolved alert(s).");

        return self::SUCCESS;
    }
}
