<?php

declare(strict_types=1);

namespace App\Console\Commands\Metrics;

use App\Actions\Metrics\RollupMetrics;
use App\Actions\Metrics\RollupRequestAnalytics;
use App\Actions\Security\RollupWafSamples;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:metrics:rollup')]
#[Description('Roll up usage metrics (10-minute and daily rows, 48 h / 30 days / 1 year) request analytics (hourly and daily rows) and web firewall results (hourly rows) and delete expired rows')]
final class RollupMetricsCommand extends Command
{
    public function handle(RollupMetrics $rollup, RollupRequestAnalytics $analytics, RollupWafSamples $waf): int
    {
        $result = $rollup->handle();
        $this->info("Rolled up {$result['rolledUp']} row(s), deleted {$result['pruned']} expired row(s).");

        $result = $analytics->handle();
        $this->info("Request analytics: rolled up {$result['rolledUp']} row(s), deleted {$result['pruned']} expired row(s).");

        $result = $waf->handle();
        $this->info("Web firewall: rolled up {$result['rolledUp']} row(s), deleted {$result['pruned']} expired row(s).");

        return self::SUCCESS;
    }
}
