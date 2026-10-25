<?php

declare(strict_types=1);

namespace App\Services\RemoteAccess;

use App\Data\Services\DatabaseSource;
use App\Enums\DatabaseEngine;
use App\Enums\RemoteAccess\RemoteAccessKind;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Sessions\SessionTargets;
use Illuminate\Support\Collection;

/**
 * Which containers a temporary access may target (docs/03 §12.2): the same
 * running replicas as a console (SSH), the serving replica of a database,
 * or the replica on a volume's server (volume access, D67), on servers whose
 * launchroomd announced the kind.
 */
final readonly class RemoteAccessTargets
{
    /** Engines with temporary access (docs/09 R39). */
    public const array ENGINES = [DatabaseEngine::Postgres, DatabaseEngine::Redis, DatabaseEngine::Valkey];

    public function __construct(
        private SessionTargets $sessions,
    ) {}

    /**
     * @return Collection<int, Placement>
     */
    public function replicas(ServiceInstance $instance): Collection
    {
        return $this->sessions->replicas($instance)
            ->filter(static fn (Placement $placement): bool => $placement->server->supportsDaemonFeature(RemoteAccessKind::Ssh->feature()))
            ->values();
    }

    public function database(ServiceInstance $instance): ?DatabaseSource
    {
        return $this->sessions->database($instance);
    }

    /**
     * The database's serving replica, if launchroomd there supports database access.
     */
    public function databaseReplica(ServiceInstance $instance): ?Placement
    {
        $placement = DatabaseWorkloads::servingPlacement($instance);

        return $placement instanceof Placement && $placement->server->supportsDaemonFeature(RemoteAccessKind::Database->feature())
            ? $placement
            : null;
    }

    /**
     * The replica of the live deployment on the volume's server, if
     * launchroomd there supports volume access. It need not run: the volume
     * does (a crash-looping service is exactly when its files need fixing).
     */
    public function volumeReplica(ServiceInstance $instance, Volume $volume): ?Placement
    {
        if ($instance->removed_at !== null || $volume->removed_at !== null || $volume->server_id === null || $instance->active_deployment_id === null) {
            return null;
        }

        return Placement::query()
            ->where('service_instance_id', $instance->id)
            ->where('deployment_id', $instance->active_deployment_id)
            ->where('server_id', $volume->server_id)
            ->where('role', WorkloadRole::Serving->value)
            ->whereIn('phase', $this->volumePhases())
            ->with('server:id,public_id,name,status,team_id,daemon_features,public_ipv4,public_ipv6')
            ->orderBy('slot')
            ->get()
            ->first(static fn (Placement $placement): bool => $placement->server->status->isApproved()
                && $placement->server->status !== ServerStatus::Offline
                && $placement->server->supportsDaemonFeature(RemoteAccessKind::Volume->feature()));
    }

    /**
     * Whether a volume access may go on (heartbeat re-check): the volume is
     * still the instance's, on that server, and the workload still placed.
     */
    public function volumeStillPlaced(ServiceInstance $instance, ?Volume $volume, string $workloadId, int $serverId): bool
    {
        return $instance->removed_at === null
            && $volume instanceof Volume
            && $volume->removed_at === null
            && $volume->service_instance_id === $instance->id
            && $volume->server_id === $serverId
            && Placement::query()
                ->where('service_instance_id', $instance->id)
                ->where('workload_id', $workloadId)
                ->where('server_id', $serverId)
                ->whereIn('phase', [...$this->volumePhases(), WorkloadPhase::Draining->value])
                ->exists();
    }

    /**
     * Phases whose workload is in the server's desired state with its volume.
     *
     * @return list<string>
     */
    private function volumePhases(): array
    {
        return array_map(static fn (WorkloadPhase $phase): string => $phase->value, [...SessionTargets::RUNNING, WorkloadPhase::Exited, WorkloadPhase::Failed]);
    }

    public static function engineSupported(DatabaseEngine $engine): bool
    {
        return in_array($engine, self::ENGINES, true);
    }

    public function stillPlaced(ServiceInstance $instance, string $workloadId, int $serverId): bool
    {
        return $this->sessions->stillPlaced($instance, $workloadId, $serverId);
    }
}
