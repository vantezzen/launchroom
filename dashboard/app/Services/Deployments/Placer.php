<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Data\Services\ScalingSettings;
use App\Data\Specs\ServiceSpec;
use App\Enums\EnvironmentKind;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
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
use App\Support\PublicId;
use App\Support\ServerPlatform;
use App\Support\Servers\HostCapacity;
use App\Support\Servers\HostRoleLimits;

/**
 * Bridges the pure scheduler (App\Scheduling\Scheduler) and the database:
 * builds the scheduler's view of the team's servers and of a replica set's
 * needs, and creates placement rows (one workload per replica slot).
 *
 * Placement rows are only ever created through createPlacement(), the one
 * place that assigns a workload id and reserves memory/CPU (the mesh-port
 * allocation hooks in there too, docs/05 §3).
 *
 * The request (PlacementRequest) carries: replicas and limits, required
 * labels, spread/pack, the allow/deny lists (server public ids → ids), the
 * environment's kind (previews only on servers allowing previews, production
 * only on servers allowing production), the image's platform for built
 * images (`deployments.plan.platform`), the servers of the current
 * deployment (preferred on ties), and the stateful pin (the volume's server).
 */
final class Placer
{
    /**
     * Places every replica slot of $deployment (all at once).
     *
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
        $instance = $deployment->serviceInstance;
        $instance->loadMissing(['volumes', 'service', 'environment']);

        $request = $this->request($instance, $spec->scaling, $deployment, $replicas ?? $spec->scaling->replicas, $existingServerIds);
        $plan = Scheduler::place($this->servers($instance->team_id, $instance->id), $request, ...$this->overcommit());

        // Services with volumes (databases, apps with uploads) are pinned to their volume's server.
        if (($instance->service->kind->isStateful() || $instance->volumes->isNotEmpty()) && $plan->serverIds !== []) {
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
     * The scheduler's view of the team's approved servers. Only `online`
     * servers are eligible; `allocated*` sum the serving workloads of every
     * other service instance.
     *
     * @return list<SchedulableServer>
     */
    public function servers(int $teamId, ?int $excludeInstanceId = null): array
    {
        $servers = Server::query()
            ->where('team_id', $teamId)
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
            ->orderBy('id')
            ->get();

        $allocated = Placement::query()
            ->whereIn('server_id', $servers->modelKeys())
            ->when($excludeInstanceId !== null, static fn ($query) => $query->where('service_instance_id', '!=', $excludeInstanceId))
            ->where('role', WorkloadRole::Serving->value)
            ->groupBy('server_id')
            ->selectRaw('server_id, sum(memory_mb) as memory, sum(cpus) as cpus')
            ->get()
            ->keyBy('server_id');

        $reserved = config()->integer('launchroom.scheduling.reserved_memory_mb');
        $controlPlane = config()->integer('launchroom.scheduling.control_plane_reserved_memory_mb');
        // Sources of open server moves drain (docs/14 §13.2).
        $moving = MovingServers::targets($teamId);

        return array_values($servers->map(static function (Server $server) use ($allocated, $reserved, $controlPlane, $moving): SchedulableServer {
            $row = $allocated->get($server->id);
            $memory = $row?->getAttribute('memory');
            $cpus = $row?->getAttribute('cpus');

            return new SchedulableServer(
                id: $server->id,
                // A role the machine can't take (a Mac on Docker Desktop, D53) doesn't place.
                roles: array_values(array_filter($server->roles->all(), static fn (ServerRole $role): bool => HostRoleLimits::refusal($server, $role) === null)),
                labels: $server->labels,
                online: $server->status === ServerStatus::Online,
                memoryMb: HostCapacity::memoryMb($server),
                allocatedMemoryMb: is_numeric($memory) ? (int) $memory : 0,
                cpus: HostCapacity::cpus($server),
                allocatedCpus: is_numeric($cpus) ? (float) $cpus : 0.0,
                allowPreviews: $server->allow_previews,
                allowProduction: $server->allow_production,
                platform: ServerPlatform::of($server),
                reservedMemoryMb: $reserved + ($server->is_control_plane ? $controlPlane : 0),
                name: $server->name,
                draining: isset($moving[$server->id]),
            );
        })->all());
    }

    /**
     * @param  list<int>  $existingServerIds
     */
    public function request(ServiceInstance $instance, ScalingSettings $scaling, ?Deployment $deployment, int $replicas, array $existingServerIds = []): PlacementRequest
    {
        $instance->loadMissing(['volumes', 'service', 'environment']);
        // A volume makes any service stateful: one replica, never moved (docs/02 §6, docs/14 §2).
        $stateful = $instance->service->kind->isStateful() || $instance->volumes->isNotEmpty();
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
        $platform = $deployment?->plan['platform'] ?? null;

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
            pinnedServerId: $stateful ? $instance->volumes->first(static fn (Volume $volume): bool => $volume->server_id !== null)?->server_id : null,
            previousServerIds: $previous,
            strategy: $scaling->strategy,
            // An allow-list naming only servers that are gone allows nothing.
            allowServerIds: $allowed,
            denyServerIds: array_values(array_filter(array_map(static fn (string $id): ?int => $ids[$id] ?? null, $scaling->denyServers), is_int(...))),
            preview: $instance->environment->kind === EnvironmentKind::Preview,
            production: $instance->environment->kind === EnvironmentKind::Production,
            platform: is_string($platform) ? $platform : null,
            existingServerIds: $existingServerIds,
        );
    }

    /**
     * @return array{float, float}
     */
    public function overcommit(): array
    {
        return [
            config()->float('launchroom.scheduling.memory_overcommit'),
            config()->float('launchroom.scheduling.cpu_overcommit'),
        ];
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
