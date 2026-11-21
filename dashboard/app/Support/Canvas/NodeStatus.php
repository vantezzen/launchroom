<?php

declare(strict_types=1);

namespace App\Support\Canvas;

use App\Data\Canvas\NodeStatusData;
use App\Data\Deployments\ContainerExitData;
use App\Data\Holds\Hold;
use App\Enums\CheckGateState;
use App\Enums\DeploymentStatus;
use App\Enums\NodeStatusReason;
use App\Enums\PlacementIssueKind;
use App\Enums\ServerStatus;
use App\Enums\ServiceRemedy;
use App\Enums\StatusTone;
use App\Enums\WorkloadFailureCode;
use App\Enums\WorkloadIssueKind;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Support\Deployments\ExitMeaning;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one derivation of a service's status (D108, docs/08 §23): from its
 * latest deployment, the observed workloads of its active deployment and
 * their servers, and holds. Every surface shows what this answers, loaded by
 * App\Queries\ServiceStatusIndex. Honest by default: a failed deploy that
 * left the previous version serving says so, and a status that needs the
 * user says why (`cause`) and offers its fix (`remedy`).
 */
final class NodeStatus
{
    /** Waiting longer than this for its server is "Not running", not "Starting". */
    private const int STARTING_MINUTES = 10;

    /**
     * @param  iterable<Placement>  $placements  workloads of the active deployment, with their `server`
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
            return self::make(NodeStatusReason::Stopped, $instance->stopped_at, $instance->activeDeployment?->public_id, remedy: $instance->activeDeployment instanceof Deployment ? ServiceRemedy::Start : null);
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
                // A push held back until its commit's checks pass (D111).
                return self::make(NodeStatusReason::Queued, $latest->queued_at, $latest->public_id, $latest->waitsForChecks() ? CheckGateState::Waiting->label() : null);
            }

            if ($status === DeploymentStatus::Failed) {
                return $active instanceof Deployment
                    ? self::make(NodeStatusReason::LastDeployFailed, $latest->finished_at, $latest->public_id, remedy: ServiceRemedy::RetryDeploy)
                    : self::make(NodeStatusReason::DeployFailed, $latest->finished_at, $latest->public_id, remedy: ServiceRemedy::RetryDeploy);
            }
        }

        if (! $active instanceof Deployment) {
            return self::make(NodeStatusReason::NotDeployed);
        }

        // Its server is down (stateful: never moved) or replicas found no other server.
        $issue = $instance->placement_issue?->kind;

        if ($issue === PlacementIssueKind::StatefulServerDown) {
            return self::make(NodeStatusReason::ServerDown, null, $active->public_id, cause: self::serverCause($placements));
        }

        if ($issue === PlacementIssueKind::Unplaceable) {
            return self::make(NodeStatusReason::Degraded, null, $active->public_id, cause: 'no server has room for every replica');
        }

        $serving = array_values(array_filter([...$placements], static fn (Placement $placement): bool => $placement->role === WorkloadRole::Serving));

        if ($serving === []) {
            return $instance->scaling->replicas > 0
                ? self::make(NodeStatusReason::NotRunning, null, $active->public_id, cause: 'not placed on a server', remedy: ServiceRemedy::StartContainers)
                : self::live($instance, $active);
        }

        // What runs on a server that stopped reporting is unknown: it
        // probably still runs (unreachable), or it is gone (offline).
        $reachable = array_values(array_filter($serving, static fn (Placement $placement): bool => ! $placement->server->status->isDown()));

        if ($reachable === []) {
            $offline = array_filter($serving, static fn (Placement $placement): bool => $placement->server->status === ServerStatus::Offline);

            return self::make(count($offline) === count($serving) ? NodeStatusReason::ServerDown : NodeStatusReason::ServerUnreachable, null, $active->public_id, cause: self::serverCause($serving));
        }

        $starting = null;

        foreach ($reachable as $placement) {
            $since = $placement->phase_changed_at ?? $placement->observed_at;

            if (in_array($placement->phase, [WorkloadPhase::Failed, WorkloadPhase::Exited], true)) {
                return self::stopped($placement, $active);
            }

            if ($placement->issue?->kind === WorkloadIssueKind::CrashLoop) {
                return self::make(NodeStatusReason::Crashed, $since, $active->public_id, cause: 'keeps crashing', remedy: ServiceRemedy::Restart);
            }

            if ($placement->phase === WorkloadPhase::Unhealthy) {
                return self::make(NodeStatusReason::Unhealthy, $since, $active->public_id, cause: 'failing its health check');
            }

            // Only an observed container is running (D68): a workload its
            // server hasn't created (or removed) is not.
            if ($placement->phase === WorkloadPhase::Removed) {
                return self::make(NodeStatusReason::NotRunning, $since, $active->public_id, cause: 'its container was removed', remedy: ServiceRemedy::StartContainers);
            }

            if (in_array($placement->phase, WorkloadPhase::starting(), true)) {
                $starting ??= $placement;
            }
        }

        if ($starting instanceof Placement) {
            // Waiting this long for its server is not "starting" anymore.
            return $starting->created_at !== null && $starting->created_at->lt(now()->subMinutes(self::STARTING_MINUTES))
                ? self::make(NodeStatusReason::NotRunning, $starting->created_at, $active->public_id, cause: 'waiting for '.$starting->server->name)
                : self::make(NodeStatusReason::Starting, $starting->created_at, $active->public_id);
        }

        return self::live($instance, $active);
    }

    /**
     * docs/design-system.md §3.3: "Live" serves public traffic, "Running" is everything else.
     */
    private static function live(ServiceInstance $instance, Deployment $active): NodeStatusData
    {
        return self::make($instance->service->kind->isWeb() ? NodeStatusReason::Live : NodeStatusReason::Running, $active->finished_at, $active->public_id);
    }

    /**
     * A container that exited or failed: "Not running" after a clean exit,
     * "Crashed" after an error, with what the exit means.
     */
    private static function stopped(Placement $placement, Deployment $active): NodeStatusData
    {
        $exit = $placement->last_exit;
        $since = $exit instanceof ContainerExitData ? CarbonImmutable::parse($exit->at) : ($placement->phase_changed_at ?? $placement->observed_at);

        if ($placement->phase === WorkloadPhase::Exited && $exit instanceof ContainerExitData && $exit->code === 0 && ! $exit->oomKilled && ! $exit->failure instanceof WorkloadFailureCode) {
            return self::make(NodeStatusReason::NotRunning, $since, $active->public_id, cause: 'exited', remedy: ServiceRemedy::StartContainers);
        }

        return self::make(NodeStatusReason::Crashed, $since, $active->public_id, cause: self::crashCause($exit), remedy: ServiceRemedy::Restart);
    }

    /**
     * "ran out of memory", "couldn't start", "exited with code 1".
     */
    private static function crashCause(?ContainerExitData $exit): string
    {
        if (! $exit instanceof ContainerExitData) {
            return 'exited';
        }

        if ($exit->failure instanceof WorkloadFailureCode && $exit->code === null) {
            return 'couldn’t start';
        }

        $meaning = ExitMeaning::notableOfExit($exit);

        if ($meaning !== null) {
            return str_starts_with($meaning, 'it ') ? substr($meaning, 3) : $meaning;
        }

        return $exit->code === null ? 'exited' : "exited with code {$exit->code}";
    }

    /**
     * "fra-1 isn't reporting", "fra-1 is offline", "its servers aren't reporting".
     *
     * @param  iterable<Placement>  $placements
     */
    private static function serverCause(iterable $placements): ?string
    {
        $servers = [];

        foreach ($placements as $placement) {
            if ($placement->server->status->isDown()) {
                $servers[$placement->server->id] = $placement->server;
            }
        }

        if ($servers === []) {
            return null;
        }

        if (count($servers) > 1) {
            return 'its servers aren’t reporting';
        }

        $server = array_values($servers)[0];

        return $server->status === ServerStatus::Offline ? "{$server->name} is offline" : "{$server->name} isn’t reporting";
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
            // Stopped on purpose is not "Not deployed".
            StatusTone::Idle, StatusTone::Staged => array_any($statuses, static fn (NodeStatusData $status): bool => $status->reason === NodeStatusReason::Stopped)
                ? self::make(NodeStatusReason::Stopped, label: $count === 1 ? 'Stopped' : "{$count} services stopped")
                : self::make(NodeStatusReason::NotDeployed),
        };
    }

    /**
     * The status line for a reason; `$label` only where the words carry
     * more than the reason (a deployment's step, a count, a hold).
     */
    private static function make(NodeStatusReason $reason, ?CarbonInterface $since = null, ?string $deploymentId = null, ?string $label = null, ?string $detail = null, ?string $cause = null, ?ServiceRemedy $remedy = null): NodeStatusData
    {
        return new NodeStatusData($reason->tone(), $reason, $label ?? $reason->label(), $since, $deploymentId, $detail, $cause, $remedy);
    }
}
