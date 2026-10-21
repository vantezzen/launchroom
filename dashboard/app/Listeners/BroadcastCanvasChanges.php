<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CanvasChanged;
use App\Events\DeploymentStatusChanged;
use App\Models\Deployment;

/**
 * Pushes deployment status changes to viewers of the environment's canvas
 * (docs/08 §4 "realtime by default").
 */
final class BroadcastCanvasChanges
{
    public function handle(DeploymentStatusChanged $event): void
    {
        $deployment = Deployment::query()
            ->whereKey($event->deploymentId)
            ->with(['environment:id,public_id', 'serviceInstance.service:id,public_id'])
            ->first();

        if ($deployment === null) {
            return;
        }

        broadcast(new CanvasChanged($deployment->environment->public_id, $deployment->serviceInstance->service->public_id));
    }
}
