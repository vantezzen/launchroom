<?php

declare(strict_types=1);

namespace App\Listeners\ServiceStatus;

use App\Events\Broadcasts\ServiceStatusChanged;
use App\Events\HoldChanged;

/**
 * An import or restore started or ended: its services are "Paused" or run
 * again (D108).
 */
final class NudgeOnHold
{
    public function handle(HoldChanged $event): void
    {
        ServiceStatusChanged::forInstances($event->instanceIds);
    }
}
