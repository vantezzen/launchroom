<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Data\Services\ScalingSettings;
use App\Data\Specs\ServiceSpec;
use App\Enums\EnvironmentKind;
use App\Enums\NodeStateField;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Scheduling\PlacementFailed;
use App\Scheduling\PlacementPlan;
use App\Scheduling\PlacementRequest;
use App\Scheduling\SchedulableServer;
use App\Scheduling\Scheduler;
use App\Services\Moves\MovingServers;
use App\Services\NodeStates\FeatureGate;
use App\Services\Volumes\MountedVolumes;
use App\Support\PublicId;
use App\Support\ServerPlatform;
use App\Support\Servers\HostRoleLimits;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bridges the pure scheduler (App\Scheduling\Scheduler) and the database:
 * builds the scheduler's view of the team's servers and of a replica set's
 * needs, and creates placement rows (one workload per replica slot).
 *
 * Limits are caps, not reservations (D72): a replica is expected to use its
 * measured peak over the last 6 hours (`workload_metric_samples`), at least
 * `replica_memory_mb`, at most its limit. A server's free memory is what it
 * reports available, minus `reserved_memory_mb` of headroom and what
 * workloads its report doesn't show yet are expected to use.
 */
final class Placer
{
    /**
     * @return list<int> ids of the servers that received workloads
     *
     * @throws PlacementFailed
     */
    public function place(Deployment $deployment, ServiceSpec $spec): array
    {
        $plan = $this->plan($deployment, $spec);

        foreach ($plan->serverIds as $slot => $serverId) {
            $this->createPlacement($deployment, $spec, $slot, $serverId);
        }

        return array_values(array_unique($plan->serverIds));
    }

    /**
     * The scheduler's plan for $deployment's replica set, without creating
     * rows. Pins a stateful service's unpinned volumes to the chosen server.
     *
     * @param  list<int>  $existingServerIds  replicas of the set that stay running
     *
     * @throws PlacementFailed
     */
    public function plan(Deployment $deployment, ServiceSpec $spec, ?int $replicas = null, array $existingServerIds = []): PlacementPlan
    {
        $instance = $deployment->loadMissing('serviceInstance')->serviceInstance;
        $instance->loadMissing(['volumes', 'service', 'environment']);

        // Shared volumes (D73): every service that mounts one runs on its server.
        $apart = MountedVolumes::apart($instance);

        if ($apart !== null) {
            throw new PlacementFailed($apart);
        }

        $request = $this->request($instance, $spec->scaling, $deployment, $replicas ?? $spec->scaling->replicas, $existingServerIds);
        $plan = Scheduler::place($this->servers($instance->team_id, $instance->id), $request);
        $mounted = MountedVolumes::for($instance);

        if ($plan->serverIds !== [] && MountedVolumes::shared($instance)->isNotEmpty() && ($first = Server::query()->whereKey($plan->serverIds[0])->first()) instanceof Server && ! FeatureGate::accepts($first, NodeStateField::SharedVolume)) {
            throw new PlacementFailed('This service mounts a volume of another service, and its server runs an older version of launchroom that can\'t share volumes. Update launchroom on the server, then deploy again.');
        }

        // Services with volumes (databases, apps with uploads) are pinned to their volume's server.
        if ($instance->isStateful() && $plan->serverIds !== []) {
            Volume::query()->whereKey($mounted->whereNull('server_id')->modelKeys())->update(['server_id' => $plan->serverIds[0]]);
            $instance->volumes()->whereNull('server_id')->update(['server_id' => $plan->serverIds[0]]);
        }

        return $plan;
    }

    public function createPlacement(Deployment $deployment, ServiceSpec $spec, int $slot, int $serverId): Placement
    {
        return Placement::query()->create([
            'team_id' => $deployment->team_id,
            'service_instance_id' => $deployment->service_instance_id,
            'deployment_id' => $deployment->id,
            'server_id' => $serverId,
            'slot' => $slot,
            'workload_id' => PublicId::generate('wl'),
            'memory_mb' => $spec->scaling->memoryMb,
            'cpus' => $spec->scaling->cpus,
        ]);
    }

    /**
     * The same replica again under a fresh workload id (a stopped stateful
     * replica that comes back after a failed rollout), keeping its limits.
     */
    public function recreatePlacement(Placement $placement): Placement
    {
        $placement->delete();

        return Placement::query()->create([
            'team_id' => $placement->team_id,
            'service_instance_id' => $placement->service_instance_id,
            'deployment_id' => $placement->deployment_id,
            'server_id' => $placement->server_id,
            'slot' => $placement->slot,
            'workload_id' => PublicId::generate('wl'),
            'memory_mb' => $placement->memory_mb,
            'cpus' => $placement->cpus,
        ]);
    }

    /**
     * The scheduler's view of the team's approved servers. Only `online`
     * servers are eligible; the workloads counted are the serving ones of
     * every other service instance.
     *
     * @return list<SchedulableServer>
     */
    public function servers(int $teamId, ?int $excludeInstanceId = null): array
    {
        $servers = Server::query()
            ->where('team_id', $teamId)
            ->approved()
            ->orderBy('id')
            ->get();

        $placements = Placement::query()
            ->whereIn('server_id', $servers->modelKeys())
            ->when($excludeInstanceId !== null, static fn ($query) => $query->where('service_instance_id', '!=', $excludeInstanceId))
            ->where('role', WorkloadRole::Serving->value)
            ->get(['server_id', 'service_instance_id', 'memory_mb', 'cpus', 'phase', 'created_at']);
        $peaks = $this->measuredMemory(array_values(array_unique($placements->map(static fn (Placement $placement): int => $placement->service_instance_id)->all())));
        $placements = $placements->groupBy('server_id');

        $headroom = config()->integer('launchroom.scheduling.reserved_memory_mb');
        $controlPlane = config()->integer('launchroom.scheduling.control_plane_reserved_memory_mb');
        // Sources of open server moves drain (docs/14 §13.2).
        $moving = MovingServers::targets($teamId);

        return array_values($servers->map(function (Server $server) use ($placements, $peaks, $headroom, $controlPlane, $moving): SchedulableServer {
            /** @var Collection<int, Placement> $here */
            $here = $placements->get($server->id) ?? new Collection;
            $expected = 0;
            $limits = 0;
            $cpus = 0.0;
            $unseen = 0;
            $measured = $server->availableMemory();

            foreach ($here as $placement) {
                $need = $this->expected($placement->memory_mb, $peaks[$placement->service_instance_id] ?? null);
                $expected += $need;
                $limits += $placement->memory_mb;
                $cpus += $placement->cpus;

                // Not in the measurement yet: placed after it, or not running yet.
                if ($measured !== null && (in_array($placement->phase, WorkloadPhase::starting(), true) || $placement->created_at?->greaterThan($measured[1]) === true)) {
                    $unseen += $need;
                }
            }

            return new SchedulableServer(
                id: $server->id,
                // A role the machine can't take (a Mac on Docker Desktop, D53) doesn't place.
                roles: array_values(array_filter($server->roles->all(), static fn (ServerRole $role): bool => HostRoleLimits::refusal($server, $role) === null)),
                labels: $server->labels,
                online: $server->status === ServerStatus::Online,
                memoryMb: $server->memoryMb(),
                allocatedMemoryMb: $expected,
                cpus: $server->cpus(),
                allocatedCpus: round($cpus, 2),
                allowPreviews: $server->allow_previews,
                allowProduction: $server->allow_production,
                platform: ServerPlatform::of($server),
                reservedMemoryMb: $headroom + ($server->is_control_plane ? $controlPlane : 0),
                name: $server->name,
                draining: isset($moving[$server->id]),
                availableMemoryMb: $measured === null ? null : $measured[0] - $headroom - $unseen,
                limitMemoryMb: $limits,
            );
        })->all());
    }

    /**
     * What one replica of $instance is expected to use with $limitMb: its
     * measured peak, at least `replica_memory_mb`, at most the limit (D72).
     */
    public function expectedMemoryMb(ServiceInstance $instance, int $limitMb): int
    {
        return $this->expected($limitMb, $this->measuredMemory([$instance->id])[$instance->id] ?? null);
    }

    /**
     * @param  list<int>  $instanceIds
     * @return array<int, int> service instance id => MB
     */
    public function measuredMemory(array $instanceIds): array
    {
        if ($instanceIds === []) {
            return [];
        }

        /** @var array<int, int> */
        return DB::table('workload_metric_samples')
            ->whereIn('service_instance_id', $instanceIds)
            ->where('resolution', 1)
            ->where('at', '>=', now()->subHours(6))
            ->whereNotNull('mem_mb')
            ->groupBy('service_instance_id')
            ->selectRaw('service_instance_id, max(mem_mb) as peak')
            ->pluck('peak', 'service_instance_id')
            ->map(static fn (mixed $peak): int => is_numeric($peak) ? (int) $peak : 0)
            ->all();
    }

    private function expected(int $limitMb, ?int $peakMb): int
    {
        return min($limitMb, max(config()->integer('launchroom.scheduling.replica_memory_mb'), $peakMb ?? 0));
    }

    /**
     * @param  list<int>  $existingServerIds
     */
    public function request(ServiceInstance $instance, ScalingSettings $scaling, ?Deployment $deployment, int $replicas, array $existingServerIds = []): PlacementRequest
    {
        $instance->loadMissing(['volumes', 'service', 'environment']);
        $mounted = MountedVolumes::for($instance);
        $stateful = $instance->isStateful();
        $ids = $this->serverIds($instance->team_id, [...$scaling->allowServers, ...$scaling->denyServers]);
        $allowed = $scaling->allowServers === [] ? null : array_values(array_filter(array_map(static fn (string $id): ?int => $ids[$id] ?? null, $scaling->allowServers), is_int(...)));

        // An allow-list naming a server that is being moved away from also
        // allows the move's target (docs/14 §13.2).
        if ($allowed !== null) {
            foreach (MovingServers::targets($instance->team_id) as $source => $target) {
                if (in_array($source, $allowed, true) && ! in_array($target, $allowed, true)) {
                    $allowed[] = $target;
                }
            }
        }
        $platform = $deployment?->plan?->platform;

        /** @var list<int> $previous */
        $previous = $instance->active_deployment_id === null ? [] : Placement::query()
            ->where('deployment_id', $instance->active_deployment_id)
            ->get(['server_id'])
            ->map(static fn (Placement $placement): int => $placement->server_id)
            ->unique()
            ->values()
            ->all();

        return new PlacementRequest(
            replicas: $replicas,
            memoryMb: $scaling->memoryMb,
            cpus: $scaling->cpus,
            requiredLabels: $scaling->placementLabels,
            stateful: $stateful,
            pinnedServerId: $stateful ? $mounted->first(static fn (Volume $volume): bool => $volume->server_id !== null)?->server_id : null,
            previousServerIds: $previous,
            strategy: $scaling->strategy,
            // An allow-list naming only servers that are gone allows nothing.
            allowServerIds: $allowed,
            denyServerIds: array_values(array_filter(array_map(static fn (string $id): ?int => $ids[$id] ?? null, $scaling->denyServers), is_int(...))),
            preview: $instance->environment->kind === EnvironmentKind::Preview,
            production: $instance->environment->kind === EnvironmentKind::Production,
            platform: $platform,
            existingServerIds: $existingServerIds,
            expectedMemoryMb: $this->expectedMemoryMb($instance, $scaling->memoryMb),
        );
    }

    /**
     * @param  list<string>  $publicIds
     * @return array<string, int>
     */
    private function serverIds(int $teamId, array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }

        $ids = [];

        // A server replaced by "Replace server" stands for its replacement (docs/14 §5.3).
        foreach (Server::query()->where('team_id', $teamId)->whereIn('public_id', $publicIds)->get(['id', 'public_id', 'replaced_by_server_id']) as $server) {
            $ids[$server->public_id] = $server->replaced_by_server_id ?? $server->id;
        }

        return $ids;
    }
}
