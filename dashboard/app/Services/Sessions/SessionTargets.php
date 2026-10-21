<?php

declare(strict_types=1);

namespace App\Services\Sessions;

use App\Data\Services\DatabaseSource;
use App\Enums\ServerStatus;
use App\Enums\Sessions\InteractiveSessionKind;
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
 * the serving replica of the database.
 */
final class SessionTargets
{
    /** Phases with a running container. */
    public const array RUNNING = [WorkloadPhase::Starting, WorkloadPhase::Running, WorkloadPhase::Healthy, WorkloadPhase::Unhealthy];

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
            ->whereIn('phase', array_map(static fn (WorkloadPhase $phase): string => $phase->value, self::RUNNING))
            ->with('server:id,public_id,name,status,team_id,daemon_features')
            ->orderBy('slot')
            ->get()
            ->filter(static fn (Placement $placement): bool => $placement->server->status->isApproved()
                && $placement->server->status !== ServerStatus::Offline
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

    public function database(ServiceInstance $instance): ?DatabaseSource
    {
        return $instance->removed_at === null ? DatabaseWorkloads::source($instance) : null;
    }

    /**
     * Whether $workloadId still runs on $serverId for this instance (heartbeat re-check).
     */
    public function stillPlaced(ServiceInstance $instance, string $workloadId, int $serverId): bool
    {
        return $instance->removed_at === null && Placement::query()
            ->where('service_instance_id', $instance->id)
            ->where('workload_id', $workloadId)
            ->where('server_id', $serverId)
            ->whereIn('phase', array_map(static fn (WorkloadPhase $phase): string => $phase->value, [...self::RUNNING, WorkloadPhase::Draining]))
            ->exists();
    }
}
