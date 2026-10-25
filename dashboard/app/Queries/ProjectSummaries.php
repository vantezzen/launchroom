<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\CanvasThumbnailData;
use App\Data\Canvas\CanvasThumbnailEdgeData;
use App\Data\Canvas\CanvasThumbnailNodeData;
use App\Data\Canvas\DeployDayData;
use App\Data\Canvas\EnvironmentSummaryData;
use App\Data\Canvas\ProjectLastDeploymentData;
use App\Data\Canvas\ProjectSummaryData;
use App\Enums\DeploymentStatus;
use App\Enums\DomainKind;
use App\Enums\EnvironmentKind;
use App\Models\CanvasLayout;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Support\Canvas\NodeStatus;
use App\Support\Canvas\ServiceGlyph;
use App\Support\Git\GitHubAvatar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The projects list of a team: per persistent environment, the number of
 * services and their aggregate status (previews are listed elsewhere), plus
 * what the project card shows (direction.md §5.2): palette slot, primary
 * domain, last deployment, 14-day deploy bars and a mini canvas of
 * production. A fixed number of queries regardless of the project count.
 */
final readonly class ProjectSummaries
{
    public function __construct(private RequestAnalytics $analytics = new RequestAnalytics) {}

    public const int DEPLOY_DAYS = 14;

    public const int THUMBNAIL_NODES = 24;

    /** Canvas node size in px (resources/js/features/canvas/auto-layout.ts). */
    private const float NODE_WIDTH = 264;

    private const float NODE_HEIGHT = 132;

    /**
     * @return list<ProjectSummaryData>
     */
    public function for(Team $team): array
    {
        $projects = Project::query()
            ->where('team_id', $team->id)
            ->with(['environments' => fn ($query) => $query->where('kind', '!=', EnvironmentKind::Preview->value)->orderByRaw("case when kind = 'production' then 0 else 1 end")->orderBy('name')])
            ->orderBy('name')
            ->get();

        $instances = ServiceInstance::query()
            ->whereIn('environment_id', $projects->flatMap(fn (Project $project) => $project->environments->modelKeys()))
            ->whereNull('removed_at')
            ->with(['service', 'activeDeployment'])
            ->orderBy('id')
            ->get();

        $latest = CanvasGraph::latestDeployments($instances);
        $placements = CanvasGraph::activePlacements($instances);
        $byEnvironment = $instances->groupBy('environment_id');

        /** @var array<int, Environment> $production project id => production environment */
        $production = [];

        foreach ($projects as $project) {
            $env = $project->environments->firstWhere('kind', EnvironmentKind::Production);

            if ($env instanceof Environment) {
                $production[$project->id] = $env;
            }
        }

        /** @var array<int, list<ServiceInstance>> $grouped environment id => instances */
        $grouped = [];

        foreach ($instances as $instance) {
            $grouped[$instance->environment_id][] = $instance;
        }

        $projectIds = array_values(array_map(intval(...), $projects->modelKeys()));
        $domains = $this->primaryDomains($production, $grouped);
        $lastDeployments = $this->lastDeployments($projectIds);
        $deploys = $this->deployDays($projectIds);
        $thumbnails = $this->thumbnails($production, $grouped);
        $requests = $this->requests($production, $grouped);

        return array_values($projects->map(static function (Project $project) use ($byEnvironment, $latest, $placements, $domains, $lastDeployments, $deploys, $thumbnails, $requests): ProjectSummaryData {
            $logos = [];
            $updated = $project->updated_at;

            $environments = array_values($project->environments->map(static function (Environment $environment) use ($byEnvironment, $latest, $placements, &$logos, &$updated): EnvironmentSummaryData {
                $statuses = [];

                foreach ($byEnvironment->get($environment->id, []) as $instance) {
                    /** @var ServiceInstance $instance */
                    $statuses[] = NodeStatus::for($instance, $latest[$instance->id] ?? null, $placements[$instance->id] ?? []);
                    $logos[$instance->service->public_id] ??= ServiceGlyph::logo($instance->service);
                    $deployedAt = ($latest[$instance->id] ?? null)?->queued_at;

                    if ($deployedAt !== null && ($updated === null || $deployedAt->greaterThan($updated))) {
                        $updated = $deployedAt;
                    }
                }

                return new EnvironmentSummaryData(
                    $environment->public_id,
                    $environment->name,
                    $environment->kind,
                    count($statuses),
                    NodeStatus::aggregate($statuses),
                );
            })->all());

            return new ProjectSummaryData(
                $project->public_id,
                $project->name,
                $project->slug,
                $environments,
                array_slice(array_values($logos), 0, 5),
                $updated,
                colorSlot: $project->color_slot,
                primaryDomain: $domains[$project->id] ?? null,
                lastDeployment: $lastDeployments[$project->id] ?? null,
                deploys: $deploys[$project->id] ?? self::emptyDays(),
                canvas: $thumbnails[$project->id] ?? null,
                iconUrl: $project->iconUrl(),
                requests: $requests[$project->id] ?? null,
            );
        })->all());
    }

    /**
     * Production requests per hour of the last 24 hours (docs/15 §5.4),
     * only for projects that had traffic.
     *
     * @param  array<int, Environment>  $production
     * @param  array<int, list<ServiceInstance>>  $byEnvironment
     * @return array<int, list<int>> project id => 24 counts
     */
    private function requests(array $production, array $byEnvironment): array
    {
        $projectOf = [];

        foreach ($production as $projectId => $environment) {
            foreach ($byEnvironment[$environment->id] ?? [] as $instance) {
                $projectOf[$instance->id] = $projectId;
            }
        }

        $out = [];

        foreach ($this->analytics->lastDay(array_keys($projectOf)) as $instanceId => $hours) {
            $projectId = $projectOf[$instanceId] ?? null;

            if ($projectId === null) {
                continue;
            }

            $sum = $out[$projectId] ?? array_fill(0, 24, 0);

            foreach ($hours as $i => $count) {
                $sum[$i] = ($sum[$i] ?? 0) + $count;
            }

            $out[$projectId] = $sum;
        }

        return array_map(array_values(...), array_filter($out, static fn (array $hours): bool => array_sum($hours) > 0));
    }

    /**
     * @param  array<int, Environment>  $production
     * @param  array<int, list<ServiceInstance>>  $byEnvironment
     * @return array<int, string> project id => hostname
     */
    private function primaryDomains(array $production, array $byEnvironment): array
    {
        $projectOf = [];

        foreach ($production as $projectId => $environment) {
            foreach ($byEnvironment[$environment->id] ?? [] as $instance) {
                $projectOf[$instance->id] = $projectId;
            }
        }

        if ($projectOf === []) {
            return [];
        }

        $custom = [];
        $generated = [];

        $rows = Domain::query()
            ->whereIn('service_instance_id', array_keys($projectOf))
            ->whereIn('kind', [DomainKind::Custom->value, DomainKind::Generated->value])
            ->whereNull('redirect_to')
            ->orderBy('id')
            ->get(['id', 'service_instance_id', 'hostname', 'kind']);

        foreach ($rows as $domain) {
            $projectId = $projectOf[$domain->service_instance_id];

            if ($domain->kind === DomainKind::Custom) {
                $custom[$projectId] ??= $domain->hostname;
            } else {
                $generated[$projectId] ??= $domain->hostname;
            }
        }

        return $custom + $generated;
    }

    /**
     * The newest deployment per project, in any environment.
     *
     * @param  list<int>  $projectIds
     * @return array<int, ProjectLastDeploymentData>
     */
    private function lastDeployments(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = Deployment::query()
            ->selectRaw('distinct on (environments.project_id) deployments.*, environments.project_id as summary_project_id, environments.public_id as summary_environment_id')
            ->join('environments', 'environments.id', '=', 'deployments.environment_id')
            ->whereIn('environments.project_id', $projectIds)
            ->orderBy('environments.project_id')
            ->orderByDesc('deployments.id')
            ->with('serviceInstance.service:id,name')
            ->get();

        $out = [];

        foreach ($rows as $deployment) {
            $projectId = $deployment->getAttribute('summary_project_id');
            $environmentId = $deployment->getAttribute('summary_environment_id');

            if (! is_numeric($projectId) || ! is_string($environmentId)) {
                continue;
            }

            $out[(int) $projectId] = new ProjectLastDeploymentData(
                id: $deployment->public_id,
                environmentId: $environmentId,
                serviceName: $deployment->serviceInstance->service->name,
                status: $deployment->status,
                message: $deployment->commit_message === null ? null : mb_substr(strtok($deployment->commit_message, "\n") ?: '', 0, 200),
                author: $deployment->commit_author,
                sha: $deployment->commit_sha === null ? null : substr($deployment->commit_sha, 0, 7),
                createdAt: $deployment->queued_at,
                authorAvatarUrl: GitHubAvatar::fromAvatarUrl($deployment->commit_author_avatar_url),
            );
        }

        return $out;
    }

    /**
     * Deployments per day (UTC) of the last 14 days, failures counted
     * separately. Cancelled deployments and ones superseded before they
     * started never ran, so they don't count.
     *
     * @param  list<int>  $projectIds
     * @return array<int, list<DeployDayData>>
     */
    private function deployDays(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $from = CarbonImmutable::now('UTC')->startOfDay()->subDays(self::DEPLOY_DAYS - 1);

        $rows = DB::table('deployments')
            ->join('environments', 'environments.id', '=', 'deployments.environment_id')
            ->whereIn('environments.project_id', $projectIds)
            ->where('deployments.queued_at', '>=', $from)
            ->where('deployments.status', '!=', DeploymentStatus::Cancelled->value)
            ->where(static fn (Builder $query) => $query->where('deployments.status', '!=', DeploymentStatus::Superseded->value)->orWhereNotNull('deployments.started_at'))
            ->groupBy('environments.project_id', DB::raw('date(deployments.queued_at)'))
            ->selectRaw('environments.project_id as project_id, date(deployments.queued_at) as day, count(*) as total, count(*) filter (where deployments.status = ?) as failed', [DeploymentStatus::Failed->value])
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            /** @var object{project_id: int|string, day: string, total: int|string, failed: int|string} $row */
            $counts[(int) $row->project_id][substr($row->day, 0, 10)] = [(int) $row->total, (int) $row->failed];
        }

        $out = [];

        foreach ($counts as $projectId => $days) {
            $out[$projectId] = self::emptyDays($days);
        }

        return $out;
    }

    /**
     * @param  array<string, array{int, int}>  $counts  Y-m-d => [total, failed]
     * @return list<DeployDayData>
     */
    private static function emptyDays(array $counts = []): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $days = [];

        for ($i = self::DEPLOY_DAYS - 1; $i >= 0; $i--) {
            $date = $today->subDays($i)->format('Y-m-d');
            [$total, $failed] = $counts[$date] ?? [0, 0];
            $days[] = new DeployDayData($date, $total, $failed);
        }

        return $days;
    }

    /**
     * @param  array<int, Environment>  $production
     * @param  array<int, list<ServiceInstance>>  $byEnvironment
     * @return array<int, CanvasThumbnailData>
     */
    private function thumbnails(array $production, array $byEnvironment): array
    {
        if ($production === []) {
            return [];
        }

        $layouts = CanvasLayout::query()
            ->whereIn('environment_id', array_map(static fn (Environment $env): int => $env->id, $production))
            ->pluck('positions', 'environment_id')
            ->all();

        /** @var array<int, list<Connection>> $connections */
        $connections = [];

        foreach (Connection::query()->whereIn('project_id', array_keys($production))->orderBy('id')->get(['project_id', 'from_service_id', 'to_service_id']) as $connection) {
            $connections[$connection->project_id][] = $connection;
        }

        $out = [];

        foreach ($production as $projectId => $environment) {
            $instances = array_slice($byEnvironment[$environment->id] ?? [], 0, self::THUMBNAIL_NODES);

            if ($instances === []) {
                continue;
            }

            $positions = $layouts[$environment->id] ?? [];
            $positions = is_string($positions) ? json_decode($positions, true) : $positions;

            $out[$projectId] = $this->thumbnail($instances, is_array($positions) ? $positions : [], $connections[$projectId] ?? []);
        }

        return $out;
    }

    /**
     * @param  non-empty-list<ServiceInstance>  $instances
     * @param  array<array-key, mixed>  $positions  service public id => {x, y}
     * @param  list<Connection>  $connections
     */
    private function thumbnail(array $instances, array $positions, array $connections): CanvasThumbnailData
    {
        /** @var non-empty-list<array{float, float}> $points */
        $points = [];
        $positioned = true;
        $fallback = 0;

        foreach ($instances as $instance) {
            $position = $positions[$instance->service->public_id] ?? null;

            if (is_array($position) && is_numeric($position['x'] ?? null) && is_numeric($position['y'] ?? null)) {
                $points[] = [(float) $position['x'], (float) $position['y']];

                continue;
            }

            // Never placed by hand: a simple grid (the canvas lays these out itself).
            $positioned = false;
            $points[] = [($fallback % 4) * self::NODE_WIDTH * 1.4, intdiv($fallback, 4) * self::NODE_HEIGHT * 1.6];
            $fallback++;
        }

        $minX = min(array_column($points, 0));
        $minY = min(array_column($points, 1));
        $width = max(array_column($points, 0)) - $minX + self::NODE_WIDTH;
        $height = max(array_column($points, 1)) - $minY + self::NODE_HEIGHT;

        $nodes = [];
        $byService = [];

        foreach ($instances as $i => $instance) {
            [$x, $y] = $points[$i];
            $service = $instance->service;
            $byService[$service->id] = $service->public_id;
            $nodes[] = new CanvasThumbnailNodeData(
                id: $service->public_id,
                kind: $service->kind,
                logo: ServiceGlyph::logo($service),
                x: round(($x - $minX + self::NODE_WIDTH / 2) / $width, 4),
                y: round(($y - $minY + self::NODE_HEIGHT / 2) / $height, 4),
                name: $service->name,
            );
        }

        $edges = [];

        foreach ($instances as $instance) {
            $parent = $instance->service->parent_service_id;

            if ($parent !== null && isset($byService[$parent])) {
                $edges[] = new CanvasThumbnailEdgeData($byService[$parent], $instance->service->public_id, 'process');
            }
        }

        foreach ($connections as $connection) {
            if (isset($byService[$connection->from_service_id], $byService[$connection->to_service_id])) {
                $edges[] = new CanvasThumbnailEdgeData($byService[$connection->from_service_id], $byService[$connection->to_service_id], 'connection');
            }
        }

        return new CanvasThumbnailData($nodes, $edges, round($width / $height, 4), $positioned);
    }
}
