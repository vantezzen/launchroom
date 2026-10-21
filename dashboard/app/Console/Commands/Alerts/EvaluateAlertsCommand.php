<?php

declare(strict_types=1);

namespace App\Console\Commands\Alerts;

use App\Actions\Alerts\EvaluateAlertConditions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:alerts:evaluate')]
#[Description('Raise and resolve the condition alerts (unhealthy services, error rates, CPU, memory, disk, certificates)')]
final class EvaluateAlertsCommand extends Command
{
    public function handle(EvaluateAlertConditions $evaluate): int
    {
        $result = $evaluate->handle();

        $this->info("Raised {$result['raised']} alert(s), resolved {$result['resolved']}.");

        return self::SUCCESS;
    }
}
