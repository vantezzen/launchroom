<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\CanvasEdgeData;
use App\Data\Canvas\CanvasNodeData;
use App\Data\Canvas\CanvasPositionData;
use App\Data\Canvas\DetectedPlanData;
use App\Data\Canvas\PlacementIssueData;
use App\Data\Specs\ChangeItem;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\StagedChanges;
use App\Enums\ChangeEffect;
use App\Enums\DomainKind;
use App\Models\CanvasLayout;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Services\Backups\BackupStatuses;
use App\Services\Backups\DataHolds;
use App\Support\Canvas\NodeStatus;
use App\Support\Canvas\ServiceGlyph;
use App\Support\Changes\SpecDiff;
use Illuminate\Database\Eloquent\Collection;

/**
 * The canvas of one environment: a node per service instance (including
 * staged removals), connection edges, process edges of workers/crons, staged
 * markers from the diff against the last applied revision, and positions.
 */
final readonly class CanvasGraph
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private BackupStatuses $backups,
    ) {}

    /**
     * @param  EnvironmentSpec|null  $current  this environment's current spec, when the caller already built it
     * @return array{nodes: list<CanvasNodeData>, edges: list<CanvasEdgeData>}
     */
    public function for(Environment $environment, ?EnvironmentSpec $current = null): array
    {
        $environment->loadMissing(['project', 'lastAppliedRevision', 'canvasLayout']);

        $instances = ServiceInstance::query()
            ->where('environment_id', $environment->id)
            ->with(['service.parent', 'domains', 'volumes', 'activeDeployment'])
            ->orderBy('id')
            ->get();

        $latest = self::latestDeployments($instances);
        $placements = self::activePlacements($instances);
        $changes = SpecDiff::between($environment->lastAppliedRevision?->spec, $current ?? $this->currentSpec->get($environment));
        $positions = $environment->canvasLayout instanceof CanvasLayout ? $environment->canvasLayout->positions : [];
        $backups = $this->backups->forEnvironment($environment);

        $nodes = [];
        $present = [];
        $holds = DataHolds::reasons();

        foreach ($instances as $instance) {
            $service = $instance->service;
            $deployment = $latest[$instance->id] ?? null;
            $framework = $this->framework($instance, $instance->activeDeployment ?? $deployment);
            $workloads = $placements[$instance->id] ?? [];
            $position = $positions[$service->public_id] ?? null;

            if ($instance->removed_at === null) {
                $present[$service->id] = $service->public_id;
            }

            $nodes[] = new CanvasNodeData(
                id: $service->public_id,
                instanceId: $instance->public_id,
                name: $service->name,
                slug: $service->slug,
                kind: $service->kind,
                logo: ServiceGlyph::logo($service, $framework),
                subtitle: ServiceGlyph::subtitle($service, $framework),
                status: NodeStatus::for($instance, $deployment, $workloads, $holds[$instance->id] ?? null),
                domain: self::primaryDomain($instance),
                privateAddress: $instance->deploy->port === null ? null : ($service->kind->isWeb() ? 'http://' : '').$service->slug.':'.$instance->deploy->port,
                replicas: $instance->scaling->replicas,
                volume: $instance->volumes->first()?->mount_path,
                server: $this->servers($workloads),
                staged: $this->stagedEffect($changes, $service->public_id),
                removed: $instance->removed_at !== null,
                enabled: $instance->enabled,
                parentId: $service->parent?->public_id,
                position: is_array($position) ? new CanvasPositionData((float) $position['x'], (float) $position['y']) : null,
                backup: $instance->removed_at === null ? ($backups[$service->public_id] ?? null) : null,
                placementIssue: PlacementIssueData::fromStored($instance->placement_issue),
            );
        }

        return ['nodes' => $nodes, 'edges' => [...$this->processEdges($nodes), ...$this->connectionEdges($environment, $present, $changes)]];
    }

    /**
     * The newest deployment of each instance.
     *
     * @param  Collection<int, ServiceInstance>  $instances
     * @return array<int, Deployment>
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
     * Workloads of each instance's active deployment, with their server.
     *
     * @param  Collection<int, ServiceInstance>  $instances
     * @return array<int, list<Placement>>
     */
    public static function activePlacements(Collection $instances): array
    {
        $active = $instances->pluck('active_deployment_id')->filter()->values()->all();

        if ($active === []) {
            return [];
        }

        $grouped = [];

        foreach (Placement::query()->whereIn('deployment_id', $active)->with('server:id,name')->orderBy('slot')->get() as $placement) {
            $grouped[$placement->service_instance_id][] = $placement;
        }

        return $grouped;
    }

    /**
     * The detected framework ("Next.js"): from the build of the deployment,
     * else from the instance's plan preview.
     */
    private function framework(ServiceInstance $instance, ?Deployment $deployment): ?string
    {
        $fromBuild = $deployment?->plan['summary'] ?? null;
        $fromPlan = $instance->plan['summary'] ?? null;
        $framework = (is_array($fromBuild) ? ($fromBuild['framework'] ?? null) : null) ?? (is_array($fromPlan) ? ($fromPlan['framework'] ?? null) : null);

        return is_string($framework) && $framework !== '' ? DetectedPlanData::frameworkLabel($framework) : null;
    }

    public static function primaryDomain(ServiceInstance $instance): ?string
    {
        /** @var Domain|null $custom */
        $custom = $instance->domains->where('kind', DomainKind::Custom)->whereNull('redirect_to')->sortBy('id')->first();
        /** @var Domain|null $generated */
        $generated = $instance->domains->where('kind', DomainKind::Generated)->sortBy('id')->first();

        return $custom->hostname ?? $generated?->hostname;
    }

    /**
     * @param  list<Placement>  $workloads
     */
    private function servers(array $workloads): ?string
    {
        $names = array_values(array_unique(array_map(static fn (Placement $placement): string => $placement->server->name, $workloads)));

        return $names === [] ? null : implode(', ', $names);
    }

    private function stagedEffect(StagedChanges $changes, string $serviceId): ?ChangeEffect
    {
        $effect = $changes->services[$serviceId] ?? null;

        if ($effect instanceof ChangeEffect && $effect !== ChangeEffect::None) {
            return $effect;
        }

        $touched = array_any($changes->items, static fn (ChangeItem $item): bool => $item->serviceId === $serviceId);

        return $touched ? ChangeEffect::None : null;
    }

    /**
     * @param  list<CanvasNodeData>  $nodes
     * @return list<CanvasEdgeData>
     */
    private function processEdges(array $nodes): array
    {
        $ids = array_flip(array_map(static fn (CanvasNodeData $node): string => $node->id, $nodes));
        $edges = [];

        foreach ($nodes as $node) {
            if ($node->parentId !== null && isset($ids[$node->parentId])) {
                $edges[] = new CanvasEdgeData('process-'.$node->id, 'process', $node->parentId, $node->id);
            }
        }

        return $edges;
    }

    /**
     * @param  array<int, string>  $present  service id => public id of non-removed instances
     * @return list<CanvasEdgeData>
     */
    private function connectionEdges(Environment $environment, array $present, StagedChanges $changes): array
    {
        $connections = Connection::query()
            ->where('project_id', $environment->project_id)
            ->whereIn('from_service_id', array_keys($present))
            ->whereIn('to_service_id', array_keys($present))
            ->orderBy('id')
            ->get();

        $edges = [];

        foreach ($connections as $connection) {
            $edges[] = new CanvasEdgeData(
                id: $connection->public_id,
                type: 'connection',
                source: $present[$connection->from_service_id],
                target: $present[$connection->to_service_id],
                variables: $connection->injected,
                mode: $connection->mode,
                style: $connection->style,
                staged: $changes->itemsUnder(['connections.'.$connection->public_id]) !== [],
            );
        }

        return $edges;
    }
}
