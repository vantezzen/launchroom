<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Deployments\SweepDeployments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:deployments:sweep')]
#[Description('Drop drained workloads and re-advance unfinished deployments (timeouts)')]
final class SweepDeploymentsCommand extends Command
{
    public function handle(SweepDeployments $sweep): int
    {
        $sweep->handle();

        return self::SUCCESS;
    }
}
