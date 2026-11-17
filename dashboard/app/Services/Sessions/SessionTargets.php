<?php

declare(strict_types=1);

namespace App\Services\Sessions;

use App\Data\Services\DatabaseSource;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Enums\WorkloadIssueKind;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Services\Backups\DatabaseWorkloads;
use Illuminate\Support\Collection;

/**
 * Which containers a session may target (docs/03 §11.4): the running
 * replicas of the service's live deployment (unhealthy ones too, that is
 * often why someone opens a console) on approved servers. The Data tab uses
 * the serving replica of the database. A recovery shell (docs/03 §11, OD9)
 * targets a replica whose container won't stay up: exited, failed, or
 * restarting again and again.
 */
final class SessionTargets
{
    /**
     * Running replicas; with $kind only those whose server allows that kind
     * of session (Report.host.features, the server's local switch).
     *
     * @return Collection<int, Placement>
     */
    public function replicas(ServiceInstance $instance, ?InteractiveSessionKind $kind = null): Collection
    {
        if ($instance->removed_at !== null || $instance->active_deployment_id === null) {
            return new Collection;
        }

        return Placement::query()
            ->where('service_instance_id', $instance->id)
            ->where('deployment_id', $instance->active_deployment_id)
            ->where('role', WorkloadRole::Serving->value)
            ->whereIn('phase', WorkloadPhase::running())
            ->with('server:id,public_id,name,status,team_id,daemon_features')
            ->orderBy('slot')
            ->get()
            ->filter(static fn (Placement $placement): bool => $placement->server->status->isReachable()
                && (! $kind instanceof InteractiveSessionKind || $placement->server->supportsDaemonFeature($kind->feature())))
            ->values();
    }

    public function replica(ServiceInstance $instance, ?string $workloadId, ?InteractiveSessionKind $kind = null): ?Placement
    {
        $replicas = $this->replicas($instance, $kind);

        return $workloadId === null
            ? $replicas->first()
            : $replicas->first(static fn (Placement $placement): bool => hash_equals($placement->workload_id, $workloadId));
    }

    /**
     * Replicas of the live deployment whose container won't stay up (a
     * recovery shell's targets), on reachable servers; with $kind only on
     * servers that allow recovery shells.
     *
     * @return Collection<int, Placement>
     */
    public function stuckReplicas(ServiceInstance $instance, ?InteractiveSessionKind $kind = null): Collection
    {
        if ($instance->removed_at !== null || $instance->active_deployment_id === null) {
            return new Collection;
        }

        return Placement::query()
            ->where('service_instance_id', $instance->id)
            ->where('deployment_id', $instance->active_deployment_id)
            ->where('role', WorkloadRole::Serving->value)
            ->whereIn('phase', [WorkloadPhase::Exited->value, WorkloadPhase::Failed->value, WorkloadPhase::Unhealthy->value, WorkloadPhase::Starting->value])
            ->with('server:id,public_id,name,status,team_id,daemon_features')
            ->orderBy('slot')
            ->get()
            ->filter(static fn (Placement $placement): bool => self::wontStayUp($placement)
                && $placement->server->status->isReachable()
                && (! $kind instanceof InteractiveSessionKind || $placement->server->supportsDaemonFeature($kind->feature())))
            ->values();
    }

    public function stuckReplica(ServiceInstance $instance, ?string $workloadId): ?Placement
    {
        $replicas = $this->stuckReplicas($instance, InteractiveSessionKind::Recovery);

        return $workloadId === null
            ? $replicas->first()
            : $replicas->first(static fn (Placement $placement): bool => hash_equals($placement->workload_id, $workloadId));
    }

    /**
     * The container exited or failed, or keeps restarting (a crash loop, a
     * hang launchroom gave up restarting).
     */
    public static function wontStayUp(Placement $placement): bool
    {
        return in_array($placement->phase, [WorkloadPhase::Exited, WorkloadPhase::Failed], true)
            || in_array($placement->issue?->kind, [WorkloadIssueKind::CrashLoop, WorkloadIssueKind::Unresponsive], true)
            || ($placement->phase === WorkloadPhase::Unhealthy && $placement->restart_count > 0);
    }

    public function database(ServiceInstance $instance): ?DatabaseSource
    {
        return $instance->removed_at === null ? DatabaseWorkloads::source($instance) : null;
    }

    /**
     * Whether $workloadId still runs on $serverId for this instance (heartbeat re-check); a recovery shell only needs the replica to still be placed there.
     */
    public function stillPlaced(ServiceInstance $instance, string $workloadId, int $serverId, ?InteractiveSessionKind $kind = null): bool
    {
        // A recovery shell runs beside a container that isn't running.
        $phases = $kind === InteractiveSessionKind::Recovery
            ? [...WorkloadPhase::running(), WorkloadPhase::Draining, WorkloadPhase::Exited, WorkloadPhase::Failed, WorkloadPhase::Pending, WorkloadPhase::Pulling]
            : [...WorkloadPhase::running(), WorkloadPhase::Draining];

        return $instance->removed_at === null && Placement::query()
            ->where('service_instance_id', $instance->id)
            ->where('workload_id', $workloadId)
            ->where('server_id', $serverId)
            ->whereIn('phase', $phases)
            ->exists();
    }
}
