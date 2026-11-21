<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\NodeStatusData;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Services\Holds\Holds;
use App\Support\Canvas\NodeStatus;
use App\Support\Canvas\ServiceGlyph;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one loader of service statuses (D108, docs/08 §23): what NodeStatus
 * needs (latest deployment, the active deployment's workloads with their
 * servers, holds) for many instances in a fixed number of queries. The
 * canvas, ⌘K, the switchers, the project cards, the top bar and the live
 * status store all get their status here, so they can't disagree.
 */
final readonly class ServiceStatusIndex
{
    /**
     * The status, name, live address and logo of every service in some
     * environments.
     *
     * @param  list<int>  $environmentIds
     * @return array<int, array<int, array{status: NodeStatusData, name: string, domain: ?string, logo: string, instance: ServiceInstance}>> environment id => service id => entry
     */
    public function in(array $environmentIds): array
    {
        if ($environmentIds === []) {
            return [];
        }

        $instances = ServiceInstance::query()
            ->whereIn('environment_id', $environmentIds)
            ->whereNull('removed_at')
            ->with(['service', 'activeDeployment', 'domains'])
            ->orderBy('id')
            ->get();

        $latest = self::latestDeployments($instances);
        $statuses = self::statuses($instances, $latest);
        $out = [];

        foreach ($instances as $instance) {
            $out[$instance->environment_id][$instance->service_id] = [
                'status' => $statuses[$instance->id],
                'name' => $instance->service->name,
                'domain' => CanvasGraph::primaryDomain($instance),
                'logo' => ServiceGlyph::forInstance($instance, $latest[$instance->id] ?? null),
                'instance' => $instance,
            ];
        }

        return $out;
    }

    /**
     * The status of each instance: the only caller of NodeStatus::for.
     * Callers that loaded the inputs already pass them.
     *
     * @param  Collection<int, ServiceInstance>  $instances  with `service` and `activeDeployment`
     * @param  array<int, Deployment>|null  $latest  self::latestDeployments()
     * @param  array<int, list<Placement>>|null  $placements  self::activePlacements()
     * @return array<int, NodeStatusData> instance id => status
     */
    public static function statuses(Collection $instances, ?array $latest = null, ?array $placements = null): array
    {
        $latest ??= self::latestDeployments($instances);
        $placements ??= self::activePlacements($instances);
        $holds = $instances->isEmpty() ? [] : Holds::blockingDeployments();
        $out = [];

        foreach ($instances as $instance) {
            $out[$instance->id] = NodeStatus::for($instance, $latest[$instance->id] ?? null, $placements[$instance->id] ?? [], $holds[$instance->id] ?? null);
        }

        return $out;
    }

    /**
     * @param  Collection<int, ServiceInstance>  $instances
     * @return array<int, Deployment> instance id => its newest deployment
     */
    public static function latestDeployments(Collection $instances): array
    {
        if ($instances->isEmpty()) {
            return [];
        }

        /** @var array<int, Deployment> $latest */
        $latest = Deployment::query()
            ->whereIn('id', Deployment::query()
                ->selectRaw('max(id)')
                ->whereIn('service_instance_id', $instances->modelKeys())
                ->groupBy('service_instance_id'))
            ->get()
            ->keyBy('service_instance_id')
            ->all();

        return $latest;
    }

    /**
     * The workloads of each instance's active deployment, with their server
     * (name and status: a service on a server that stopped reporting says so).
     *
     * @param  Collection<int, ServiceInstance>  $instances
     * @return array<int, list<Placement>> instance id => workloads
     */
    public static function activePlacements(Collection $instances): array
    {
        $active = $instances->pluck('active_deployment_id')->filter()->values()->all();

        if ($active === []) {
            return [];
        }

        $grouped = [];

        foreach (Placement::query()->whereIn('deployment_id', $active)->with('server:id,public_id,name,status')->orderBy('slot')->get() as $placement) {
            $grouped[$placement->service_instance_id][] = $placement;
        }

        return $grouped;
    }
}
