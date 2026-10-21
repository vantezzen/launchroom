<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Servers\ServerPlacementData;
use App\Data\Servers\ServerWorkloadData;
use App\Enums\WorkloadRole;
use App\Models\Placement;
use App\Models\Server;
use App\Scheduling\SchedulableServer;
use App\Services\Deployments\Placer;

/**
 * The placement section of a server's page: settings, scheduler capacity
 * and the replicas placed there (docs/02 §2, docs/08).
 */
final readonly class ServerWorkloads
{
    public function __construct(
        private Placer $placer,
    ) {}

    public function for(Server $server, bool $canUpdate): ServerPlacementData
    {
        $placements = Placement::query()
            ->where('server_id', $server->id)
            ->with(['serviceInstance.service', 'deployment.environment.project'])
            ->orderBy('id')
            ->limit(500)
            ->get();

        $workloads = $placements->map(static fn (Placement $placement): ServerWorkloadData => new ServerWorkloadData(
            workloadId: $placement->workload_id,
            serviceId: $placement->serviceInstance->service->public_id,
            serviceName: $placement->serviceInstance->service->name,
            environmentId: $placement->deployment->environment->public_id,
            environmentName: $placement->deployment->environment->name,
            projectId: $placement->deployment->environment->project->public_id,
            projectName: $placement->deployment->environment->project->name,
            deploymentId: $placement->deployment->public_id,
            slot: $placement->slot,
            role: $placement->role,
            phase: $placement->phase,
            active: $placement->deployment_id === $placement->serviceInstance->active_deployment_id,
            memoryMb: $placement->memory_mb,
            cpus: $placement->cpus,
        ))->sortBy([['projectName', 'asc'], ['environmentName', 'asc'], ['serviceName', 'asc'], ['slot', 'asc']])->values()->all();

        $view = array_find($this->placer->servers($server->team_id), static fn (SchedulableServer $s): bool => $s->id === $server->id);
        [$memoryOvercommit, $cpuOvercommit] = $this->placer->overcommit();
        $serving = $placements->filter(static fn (Placement $placement): bool => $placement->role === WorkloadRole::Serving);

        return new ServerPlacementData(
            labels: $server->labels,
            allowPreviews: $server->allow_previews,
            allowProduction: $server->allow_production,
            canUpdate: $canUpdate,
            allocatedMemoryMb: $serving->sum(static fn (Placement $placement): int => $placement->memory_mb),
            schedulableMemoryMb: $view?->memoryMb === null ? null : max(0, (int) floor($view->memoryMb * $memoryOvercommit) - $view->reservedMemoryMb),
            allocatedCpus: round($serving->sum(static fn (Placement $placement): float => $placement->cpus), 2),
            schedulableCpus: $view?->cpus === null ? null : $view->cpus * $cpuOvercommit,
            workloads: array_values($workloads),
            measureLinks: $server->measure_links,
        );
    }
}
