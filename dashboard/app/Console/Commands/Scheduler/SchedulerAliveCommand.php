<?php

declare(strict_types=1);

namespace App\Console\Commands\Scheduler;

use App\Services\Scheduling\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The scheduler container's healthcheck (deploy/control-plane/compose.yml):
 * exit 0 while the schedule's heartbeat is under 3 minutes old.
 */
#[Signature('launchroom:scheduler:alive')]
#[Description('Exit 0 when the scheduler wrote its heartbeat in the last 3 minutes, else 1')]
final class SchedulerAliveCommand extends Command
{
    public function handle(SchedulerHeartbeat $heartbeat): int
    {
        if ($heartbeat->alive()) {
            return self::SUCCESS;
        }

        $beat = $heartbeat->last();
        $this->line($beat === null
            ? 'The scheduler has not written a heartbeat yet.'
            : 'The scheduler’s last heartbeat was '.(int) $beat['at']->diffInSeconds(CarbonImmutable::now(), true).' seconds ago.');

        return self::FAILURE;
    }
}
