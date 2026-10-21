<?php

declare(strict_types=1);

namespace App\Support\Canvas;

use App\Data\Canvas\NodeStatusData;
use App\Enums\CanvasNodeState;
use App\Enums\DeploymentStatus;
use App\Enums\WorkloadPhase;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\ServiceInstance;

/**
 * Derives a node's status line from its latest deployment and the observed
 * workloads of its active deployment. Honest by default: a failed deploy
 * that left the previous version serving says so.
 */
final class NodeStatus
{
    /**
     * @param  iterable<Placement>  $placements  workloads of the active deployment
     */
    public static function for(ServiceInstance $instance, ?Deployment $latest, iterable $placements = []): NodeStatusData
    {
        if ($instance->removed_at !== null) {
            return new NodeStatusData(CanvasNodeState::Idle, 'Removal staged', $instance->removed_at);
        }

        if (! $instance->enabled) {
            return new NodeStatusData(CanvasNodeState::Idle, 'Disabled');
        }

        $active = $instance->activeDeployment;

        if ($latest instanceof Deployment && (! $active instanceof Deployment || $latest->id !== $active->id)) {
            $status = $latest->status;

            if ($status->isInFlight()) {
                return new NodeStatusData(CanvasNodeState::Progress, $status->label(), $latest->started_at ?? $latest->queued_at, $latest->public_id);
            }

            if ($status === DeploymentStatus::Queued) {
                return new NodeStatusData(CanvasNodeState::Idle, 'Queued', $latest->queued_at, $latest->public_id);
            }

            if ($status === DeploymentStatus::Failed) {
                return $active instanceof Deployment
                    ? new NodeStatusData(CanvasNodeState::Warning, 'Last deploy failed', $latest->finished_at, $latest->public_id)
                    : new NodeStatusData(CanvasNodeState::Failed, 'Failed', $latest->finished_at, $latest->public_id);
            }
        }

        if (! $active instanceof Deployment) {
            return new NodeStatusData(CanvasNodeState::Idle, 'Not deployed');
        }

        // Its server is down (stateful: never moved) or replicas found no other server.
        $issue = $instance->placement_issue['kind'] ?? null;

        if ($issue === 'stateful_server_down') {
            return new NodeStatusData(CanvasNodeState::Failed, 'Server down', null, $active->public_id);
        }

        if ($issue === 'unplaceable') {
            return new NodeStatusData(CanvasNodeState::Warning, 'Degraded', null, $active->public_id);
        }

        foreach ($placements as $placement) {
            if (in_array($placement->phase, [WorkloadPhase::Failed, WorkloadPhase::Exited], true) && $placement->role->value === 'serving') {
                return new NodeStatusData(CanvasNodeState::Failed, 'Crashed', $placement->observed_at, $active->public_id);
            }

            if ($placement->phase === WorkloadPhase::Unhealthy) {
                return new NodeStatusData(CanvasNodeState::Warning, 'Unhealthy', $placement->observed_at, $active->public_id);
            }
        }

        // Unified status words (docs/design-system.md §3.3): "Live" serves
        // public traffic, "Running" is everything else (workers, databases).
        $word = $instance->service->kind->isWeb() ? 'Live' : 'Running';

        return new NodeStatusData(CanvasNodeState::Active, $word, $active->finished_at, $active->public_id);
    }

    /**
     * The most urgent of several statuses (for an environment summary).
     *
     * @param  list<NodeStatusData>  $statuses
     */
    public static function aggregate(array $statuses): NodeStatusData
    {
        if ($statuses === []) {
            return new NodeStatusData(CanvasNodeState::Idle, 'No services');
        }

        $worst = $statuses[0];
        $counts = [];

        foreach ($statuses as $status) {
            $counts[$status->state->value] = ($counts[$status->state->value] ?? 0) + 1;

            if ($status->state->rank() > $worst->state->rank()) {
                $worst = $status;
            }
        }

        $count = $counts[$worst->state->value] ?? 1;
        $total = count($statuses);

        return match ($worst->state) {
            CanvasNodeState::Failed => new NodeStatusData(CanvasNodeState::Failed, $count === 1 ? '1 service failed' : "{$count} services failed"),
            CanvasNodeState::Warning => new NodeStatusData(CanvasNodeState::Warning, $count === 1 ? '1 service needs attention' : "{$count} services need attention"),
            CanvasNodeState::Progress => new NodeStatusData(CanvasNodeState::Progress, 'Deploying'),
            CanvasNodeState::Active => new NodeStatusData(CanvasNodeState::Active, $total === $count ? 'Live' : "{$count} of {$total} running"),
            CanvasNodeState::Idle => new NodeStatusData(CanvasNodeState::Idle, 'Not deployed'),
        };
    }
}
