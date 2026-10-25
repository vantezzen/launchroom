<?php

declare(strict_types=1);

namespace App\Listeners\Security;

use App\Enums\DeploymentStatus;
use App\Events\DeploymentStatusChanged;
use App\Jobs\Security\RequestInventoryJob;

/**
 * A deployment went live: inventory its image and re-evaluate the team's
 * findings (docs/22 §3.1, §5.5). Never changes the deployment's outcome.
 */
final class RequestInventoryOnLive
{
    public function handle(DeploymentStatusChanged $event): void
    {
        if ($event->to === DeploymentStatus::Active) {
            dispatch(new RequestInventoryJob($event->deploymentId));
        }
    }
}
