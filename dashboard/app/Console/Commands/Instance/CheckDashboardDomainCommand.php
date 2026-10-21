<?php

declare(strict_types=1);

namespace App\Console\Commands\Instance;

use App\Jobs\Instance\CheckDashboardDomainJob;
use App\Models\DashboardDomainChange;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:dashboard-domain:check')]
#[Description('Re-check DNS, the certificate and HTTPS of a dashboard domain being set up (docs/05 §4.1)')]
final class CheckDashboardDomainCommand extends Command
{
    public function handle(): int
    {
        $change = DashboardDomainChange::open();

        if ($change instanceof DashboardDomainChange) {
            dispatch(new CheckDashboardDomainJob($change->id));
        }

        return self::SUCCESS;
    }
}
