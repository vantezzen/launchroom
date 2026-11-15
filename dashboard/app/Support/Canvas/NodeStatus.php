<?php

declare(strict_types=1);

namespace App\Support\Canvas;

use App\Data\Canvas\NodeStatusData;
use App\Data\Holds\Hold;
use App\Enums\DeploymentStatus;
use App\Enums\NodeStatusReason;
use App\Enums\PlacementIssueKind;
use App\Enums\StatusTone;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use Carbon\CarbonInterface;

/**
 * Derives a node's status line from its latest deployment and the observed
 * workloads of its active deployment. Honest by default: a failed deploy
 * that left the previous version serving says so.
 */
final class NodeStatus
{
    /**
     * @param  iterable<Placement>  $placements  workloads of the active deployment
     * @param  Hold|null  $hold  an import or restore holds it (App\Services\Holds\Holds)
     */
    public static function for(ServiceInstance $instance, ?Deployment $latest, iterable $placements = [], ?Hold $hold = null): NodeStatusData
    {
        if ($instance->removed_at !== null) {
            return self::make(NodeStatusReason::RemovalStaged, $instance->removed_at);
        }

        if (! $instance->enabled) {
            return self::make(NodeStatusReason::Disabled);
        }

        // Stopped in launchroom (D68): keeps everything, runs nothing.
        if ($instance->stopped_at !== null) {
            return self::make(NodeStatusReason::Stopped, $instance->stopped_at, $instance->activeDeployment?->public_id);
        }

        // Out of its servers' desired state until an import or restore ends.
        if ($hold instanceof Hold) {
            return self::make(NodeStatusReason::Paused, null, $instance->activeDeployment?->public_id, 'Paused: '.$hold->reason, $hold->reason);
        }

        $active = $instance->activeDeployment;

        if ($latest instanceof Deployment && (! $active instanceof Deployment || $latest->id !== $active->id)) {
            $status = $latest->status;

            if ($status->isInFlight()) {
                return self::make(NodeStatusReason::Deploying, $latest->started_at ?? $latest->queued_at, $latest->public_id, $status->stage());
            }

            if ($status === DeploymentStatus::Queued) {
                return self::make(NodeStatusReason::Queued, $latest->queued_at, $latest->public_id);
            }

            if ($status === DeploymentStatus::Failed) {
                return $active instanceof Deployment
                    ? self::make(NodeStatusReason::LastDeployFailed, $latest->finished_at, $latest->public_id)
                    : self::make(NodeStatusReason::DeployFailed, $latest->finished_at, $latest->public_id);
            }
        }

        if (! $active instanceof Deployment) {
            return self::make(NodeStatusReason::NotDeployed);
        }

        // Its server is down (stateful: never moved) or replicas found no other server.
        $issue = $instance->placement_issue?->kind;

        if ($issue === PlacementIssueKind::StatefulServerDown) {
            return self::make(NodeStatusReason::ServerDown, null, $active->public_id);
        }

        if ($issue === PlacementIssueKind::Unplaceable) {
            return self::make(NodeStatusReason::Degraded, null, $active->public_id);
        }

        $serving = 0;
        $starting = null;

        foreach ($placements as $placement) {
            if ($placement->role !== WorkloadRole::Serving) {
                continue;
            }

            $serving++;

            if (in_array($placement->phase, [WorkloadPhase::Failed, WorkloadPhase::Exited], true)) {
                return self::make(NodeStatusReason::Crashed, $placement->observed_at, $active->public_id);
            }

            if ($placement->phase === WorkloadPhase::Unhealthy) {
                return self::make(NodeStatusReason::Unhealthy, $placement->observed_at, $active->public_id);
            }

            // Only an observed container is running (D68): a workload its
            // server hasn't created (or removed) is not.
            if ($placement->phase === WorkloadPhase::Removed) {
                return self::make(NodeStatusReason::NotRunning, $placement->observed_at, $active->public_id);
            }

            if (in_array($placement->phase, [WorkloadPhase::Pending, WorkloadPhase::Pulling, WorkloadPhase::Starting], true)) {
                $starting ??= $placement;
            }
        }

        if ($serving === 0 && $instance->scaling->replicas > 0) {
            return self::make(NodeStatusReason::NotRunning, null, $active->public_id);
        }

        if ($starting instanceof Placement) {
            // Waiting this long for its server is not "starting" anymore.
            return $starting->created_at !== null && $starting->created_at->lt(now()->subMinutes(10))
                ? self::make(NodeStatusReason::NotRunning, $starting->observed_at, $active->public_id)
                : self::make(NodeStatusReason::Starting, $starting->created_at, $active->public_id);
        }

        // docs/design-system.md §3.3: "Live" serves public traffic, "Running" is everything else.
        return self::make($instance->service->kind->isWeb() ? NodeStatusReason::Live : NodeStatusReason::Running, $active->finished_at, $active->public_id);
    }

    /**
     * The most urgent of several statuses (for an environment summary).
     *
     * @param  list<NodeStatusData>  $statuses
     */
    public static function aggregate(array $statuses): NodeStatusData
    {
        if ($statuses === []) {
            return self::make(NodeStatusReason::NoServices);
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
            StatusTone::Failed => self::make(NodeStatusReason::ServicesFailed, label: $count === 1 ? '1 service failed' : "{$count} services failed"),
            StatusTone::Warning => self::make(NodeStatusReason::ServicesNeedAttention, label: $count === 1 ? '1 service needs attention' : "{$count} services need attention"),
            StatusTone::Progress => self::make(NodeStatusReason::Deploying),
            StatusTone::Active => $total === $count ? self::make(NodeStatusReason::Live) : self::make(NodeStatusReason::PartlyRunning, label: "{$count} of {$total} running"),
            StatusTone::Idle, StatusTone::Staged => self::make(NodeStatusReason::NotDeployed),
        };
    }

    /**
     * The status line for a reason; `$label` only where the words carry
     * more than the reason (a deployment's step, a count, a hold).
     */
    private static function make(NodeStatusReason $reason, ?CarbonInterface $since = null, ?string $deploymentId = null, ?string $label = null, ?string $detail = null): NodeStatusData
    {
        return new NodeStatusData($reason->tone(), $reason, $label ?? $reason->label(), $since, $deploymentId, $detail);
    }
}
