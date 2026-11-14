<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\NodeStatusData;
use App\Models\ServiceInstance;
use App\Support\Canvas\NodeStatus;
use App\Support\Canvas\ServiceGlyph;

/**
 * The status word, live address and logo of every service in some
 * environments (⌘K and the project switcher, docs/08 §8): the same
 * NodeStatus the canvas node shows. A fixed number of queries.
 */
final readonly class ServiceStatusIndex
{
    /**
     * @param  list<int>  $environmentIds
     * @return array<int, array<int, array{status: NodeStatusData, domain: ?string, logo: string}>> environment id => service id => entry
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
            ->get();

        $latest = CanvasGraph::latestDeployments($instances);
        $placements = CanvasGraph::activePlacements($instances);
        $out = [];

        foreach ($instances as $instance) {
            $out[$instance->environment_id][$instance->service_id] = [
                'status' => NodeStatus::for($instance, $latest[$instance->id] ?? null, $placements[$instance->id] ?? []),
                'domain' => CanvasGraph::primaryDomain($instance),
                'logo' => ServiceGlyph::logo($instance->service),
            ];
        }

        return $out;
    }
}
