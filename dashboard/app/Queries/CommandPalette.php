<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\NodeStatusData;
use App\Data\CommandPalette\PaletteData;
use App\Data\CommandPalette\PaletteItemData;
use App\Enums\AnalyticsView;
use App\Enums\NodeStatusReason;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Sessions\InteractiveSession;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use App\Support\PublicId;
use Illuminate\Support\Facades\Gate;

/**
 * The ⌘K index of the current team (docs/08 §8): projects, environments,
 * services, recent deployments and servers. Names, ids, statuses and live
 * addresses only, never variable values.
 *
 * Services carry the status of production, or of `?environment=` (the page
 * the palette is opened on) for that environment's project, so the
 * project's services can be listed first with the status their nodes show.
 * Projects carry the aggregate status of that same environment (ProjectStatuses).
 */
final readonly class CommandPalette
{
    private const int RECENT_DEPLOYMENTS = 30;

    public function __construct(private ServiceStatusIndex $statuses) {}

    /**
     * @param  string|null  $environment  `?environment=`, the page the palette is opened on
     */
    public function for(Team $team, User $user, ?string $environment): PaletteData
    {
        $gate = Gate::forUser($user);
        $projects = Project::query()->where('team_id', $team->id)->orderBy('name')->get(['id', 'public_id', 'name', 'icon_path', 'icon_version']);
        $projectIds = $projects->mapWithKeys(static fn (Project $project): array => [$project->id => $project->public_id])->all();
        $projectNames = $projects->mapWithKeys(static fn (Project $project): array => [$project->id => $project->name])->all();

        $environments = Environment::query()
            ->where('team_id', $team->id)
            ->inDisplayOrder()
            ->get(['id', 'public_id', 'name', 'project_id', 'kind', 'team_id']);

        $context = $this->contextEnvironment($user, $environment, $environments->all());
        $shown = ProjectStatuses::shown($environments, $context);

        $index = $this->statuses->in(array_values(array_map(static fn (Environment $environment): int => $environment->id, $shown)));

        $services = Service::query()->where('team_id', $team->id)->orderBy('name')->get(['id', 'public_id', 'name', 'slug', 'project_id', 'kind']);

        $deployments = Deployment::query()
            ->where('team_id', $team->id)
            ->with(['environment:id,public_id,name,project_id', 'serviceInstance:id,service_id', 'serviceInstance.service:id,name'])
            ->latest('id')
            ->limit(self::RECENT_DEPLOYMENTS)
            ->get(['id', 'public_id', 'environment_id', 'service_instance_id', 'status', 'commit_sha', 'commit_message']);

        $servers = Server::query()->where('team_id', $team->id)->orderBy('name')->get(['id', 'public_id', 'name', 'status']);

        $serviceItems = [];

        foreach ($services as $service) {
            $environment = $shown[$service->project_id] ?? null;
            $entry = $environment instanceof Environment ? ($index[$environment->id][$service->id] ?? null) : null;

            // The open environment lists only what it has (its panel would 404).
            if ($entry === null && $context instanceof Environment && $service->project_id === $context->project_id) {
                continue;
            }

            $serviceItems[] = new PaletteItemData(
                kind: 'service',
                id: $service->public_id,
                label: $service->name,
                detail: isset($projectNames[$service->project_id]) ? $service->kind->label().' in '.$projectNames[$service->project_id] : $service->kind->label(),
                projectId: $projectIds[$service->project_id] ?? null,
                environmentId: $environment?->public_id,
                nodeStatus: $entry['status'] ?? null,
                domain: $entry['domain'] ?? null,
                logo: $entry['logo'] ?? null,
                serviceKind: $service->kind,
                analyticsView: $entry === null ? null : AnalyticsView::forService($entry['domain'] !== null),
            );
        }

        return new PaletteData(
            projects: array_values($projects->map(static function (Project $project) use ($shown, $index): PaletteItemData {
                $environment = $shown[$project->id] ?? null;

                return new PaletteItemData(
                    kind: 'project',
                    id: $project->public_id,
                    label: $project->name,
                    projectId: $project->public_id,
                    environmentId: $environment?->public_id,
                    iconUrl: $project->iconUrl(),
                    nodeStatus: $environment instanceof Environment ? ProjectStatuses::aggregate($index[$environment->id] ?? []) : null,
                );
            })->all()),
            environments: array_values($environments->map(static fn (Environment $environment): PaletteItemData => new PaletteItemData(
                kind: 'environment',
                id: $environment->public_id,
                label: $environment->name,
                detail: $projectNames[$environment->project_id] ?? null,
                projectId: $projectIds[$environment->project_id] ?? null,
                environmentId: $environment->public_id,
            ))->all()),
            services: $serviceItems,
            deployments: array_values($deployments->map(static fn (Deployment $deployment): PaletteItemData => new PaletteItemData(
                kind: 'deployment',
                id: $deployment->public_id,
                label: $deployment->serviceInstance->service->name.($deployment->commit_sha === null ? '' : ' at '.substr($deployment->commit_sha, 0, 7)),
                detail: $deployment->environment->name.($deployment->commit_message === null ? '' : ': '.mb_substr(strtok($deployment->commit_message, "\n") ?: '', 0, 80)),
                projectId: $projectIds[$deployment->environment->project_id] ?? null,
                environmentId: $deployment->environment->public_id,
                status: $deployment->status->value,
                // The live deployment of a service that isn't serving says what the service does (D108).
                nodeStatus: self::notServing($deployment, $index),
            ))->all()),
            servers: array_values($servers->map(static fn (Server $server): PaletteItemData => new PaletteItemData(
                kind: 'server',
                id: $server->public_id,
                label: $server->name,
                status: $server->status->value,
            ))->all()),
            canDeploy: $gate->allows('deployAny', [Environment::class, $team]),
            canWriteVariables: $gate->allows('writeAny', [Variable::class, $team]),
            canData: $gate->allows('viewAny', [InteractiveSession::class, $team, InteractiveSessionKind::Data]),
        );
    }

    /**
     * The service's status when this is its live deployment and the service
     * is stopped or doesn't serve, else null (the deployment's own word).
     *
     * @param  array<int, array<int, array{status: NodeStatusData, instance: ServiceInstance}>>  $index  ServiceStatusIndex::in()
     */
    private static function notServing(Deployment $deployment, array $index): ?NodeStatusData
    {
        $entry = $index[$deployment->environment_id][$deployment->serviceInstance->service_id] ?? null;

        if ($entry === null || $entry['instance']->active_deployment_id !== $deployment->id) {
            return null;
        }

        $reason = $entry['status']->reason;

        return $reason === NodeStatusReason::Stopped || $reason->isNotServing() ? $entry['status'] : null;
    }

    /**
     * Only one of the team's environments (already loaded through the team)
     * that the user may view.
     *
     * @param  array<int, Environment>  $environments  the team’s environments
     */
    private function contextEnvironment(User $user, ?string $id, array $environments): ?Environment
    {
        if ($id === null || ! PublicId::isValid($id, Environment::publicIdPrefix())) {
            return null;
        }

        foreach ($environments as $environment) {
            if ($environment->public_id === $id) {
                return Gate::forUser($user)->allows('view', $environment) ? $environment : null;
            }
        }

        return null;
    }
}
