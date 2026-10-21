<?php

declare(strict_types=1);

namespace App\Console\Commands\Instance;

use App\Services\Instance\DashboardOrigin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:dashboard-url')]
#[Description('Print the dashboard’s canonical address (Settings → Dashboard domain, else APP_URL)')]
final class DashboardUrlCommand extends Command
{
    public function handle(DashboardOrigin $origin): int
    {
        $this->line($origin->canonical());

        return self::SUCCESS;
    }
}
