<?php

declare(strict_types=1);

namespace App\Console\Commands\Metrics;

use App\Actions\Metrics\RollupMetrics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:metrics:rollup')]
#[Description('Roll up usage metrics to 10-minute and daily rows and delete expired ones (48 h / 30 days / 1 year)')]
final class RollupMetricsCommand extends Command
{
    public function handle(RollupMetrics $rollup): int
    {
        $result = $rollup->handle();

        $this->info("Rolled up {$result['rolledUp']} row(s), deleted {$result['pruned']} expired row(s).");

        return self::SUCCESS;
    }
}
