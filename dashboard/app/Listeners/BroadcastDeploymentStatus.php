<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Broadcasts\DeploymentUpdated;
use App\Events\CanvasChanged;
use App\Events\DeploymentStatusChanged;
use App\Models\Deployment;

/**
 * Pushes deployment status changes to open dashboards (environment and
 * deployment channels).
 */
final class BroadcastDeploymentStatus
{
    public function handle(DeploymentStatusChanged $event): void
    {
        $deployment = Deployment::query()
            ->whereKey($event->deploymentId)
            ->with(['environment:id,public_id', 'serviceInstance:id,service_id', 'serviceInstance.service:id,public_id'])
            ->first();

        if ($deployment === null) {
            return;
        }

        broadcast(new DeploymentUpdated(
            $deployment->public_id,
            $deployment->environment->public_id,
            $deployment->serviceInstance->service->public_id,
            $event->to->value,
        ));

        // Canvas nodes show the service's deployment status.
        broadcast(new CanvasChanged($deployment->environment->public_id, $deployment->serviceInstance->service->public_id));
    }
}
