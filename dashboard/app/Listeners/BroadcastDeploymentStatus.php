<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Broadcasts\DeploymentUpdated;
use App\Events\CanvasChanged;
use App\Events\DeploymentStatusChanged;
use App\Models\Deployment;
use App\Models\Membership;

/**
 * Pushes deployment status changes to open dashboards (environment and
 * deployment channels, and the member channel of whoever started it).
 */
final class BroadcastDeploymentStatus
{
    public function handle(DeploymentStatusChanged $event): void
    {
        $deployment = Deployment::query()
            ->whereKey($event->deploymentId)
            ->with(['environment:id,public_id,project_id', 'environment.project:id,public_id', 'serviceInstance:id,service_id', 'serviceInstance.service:id,public_id,name'])
            ->first();

        if ($deployment === null) {
            return;
        }

        // Whoever started it follows it on their own channel, on any page.
        $member = $deployment->actor_id === null ? null : Membership::query()
            ->where('team_id', $deployment->team_id)
            ->where('user_id', $deployment->actor_id)
            ->value('public_id');

        broadcast(new DeploymentUpdated(
            $deployment->public_id,
            $deployment->environment->public_id,
            $deployment->serviceInstance->service->public_id,
            $event->to->value,
            statusChanged: true,
            memberId: is_string($member) ? $member : null,
            projectId: $deployment->environment->project->public_id,
            serviceName: $deployment->serviceInstance->service->name,
            commitSha: $deployment->commit_sha,
        ));

        // Canvas nodes show the service's deployment status.
        broadcast(new CanvasChanged($deployment->environment->public_id, $deployment->serviceInstance->service->public_id));
    }
}
