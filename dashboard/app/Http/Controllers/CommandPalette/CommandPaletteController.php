<?php

declare(strict_types=1);

namespace App\Http\Controllers\CommandPalette;

use App\Data\CommandPalette\PaletteData;
use App\Data\CommandPalette\PaletteItemData;
use App\Enums\EnvironmentKind;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The ⌘K index of the current team (docs/08 §4): projects, environments,
 * services, recent deployments and servers. Names and ids only.
 */
final class CommandPaletteController extends Controller
{
    private const int RECENT_DEPLOYMENTS = 30;

    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [Project::class, $team]);

        $projects = Project::query()->where('team_id', $team->id)->orderBy('name')->get(['id', 'public_id', 'name', 'icon_path', 'icon_version']);
        $projectIds = $projects->mapWithKeys(static fn (Project $project): array => [$project->id => $project->public_id])->all();
        $projectNames = $projects->mapWithKeys(static fn (Project $project): array => [$project->id => $project->name])->all();

        $environments = Environment::query()
            ->where('team_id', $team->id)
            ->orderByRaw("CASE kind WHEN 'production' THEN 0 WHEN 'persistent' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get(['id', 'public_id', 'name', 'project_id', 'kind']);
        $production = $environments
            ->filter(static fn (Environment $environment): bool => $environment->kind === EnvironmentKind::Production)
            ->mapWithKeys(static fn (Environment $environment): array => [$environment->project_id => $environment->public_id])
            ->all();

        $services = Service::query()->where('team_id', $team->id)->orderBy('name')->get(['id', 'public_id', 'name', 'slug', 'project_id', 'kind']);

        $deployments = Deployment::query()
            ->where('team_id', $team->id)
            ->with(['environment:id,public_id,name,project_id', 'serviceInstance:id,service_id', 'serviceInstance.service:id,name'])
            ->latest('id')
            ->limit(self::RECENT_DEPLOYMENTS)
            ->get(['id', 'public_id', 'environment_id', 'service_instance_id', 'status', 'commit_sha', 'commit_message']);

        $servers = Server::query()->where('team_id', $team->id)->orderBy('name')->get(['id', 'public_id', 'name', 'status']);

        return response()->json(new PaletteData(
            projects: array_values($projects->map(static fn (Project $project): PaletteItemData => new PaletteItemData(
                kind: 'project',
                id: $project->public_id,
                label: $project->name,
                projectId: $project->public_id,
                environmentId: $production[$project->id] ?? null,
                iconUrl: $project->iconUrl(),
            ))->all()),
            environments: array_values($environments->map(static fn (Environment $environment): PaletteItemData => new PaletteItemData(
                kind: 'environment',
                id: $environment->public_id,
                label: $environment->name,
                detail: $projectNames[$environment->project_id] ?? null,
                projectId: $projectIds[$environment->project_id] ?? null,
                environmentId: $environment->public_id,
            ))->all()),
            services: array_values($services->map(static fn (Service $service): PaletteItemData => new PaletteItemData(
                kind: 'service',
                id: $service->public_id,
                label: $service->name,
                detail: ($projectNames[$service->project_id] ?? '').' · '.$service->kind->label(),
                projectId: $projectIds[$service->project_id] ?? null,
                environmentId: $production[$service->project_id] ?? null,
            ))->all()),
            deployments: array_values($deployments->map(static fn (Deployment $deployment): PaletteItemData => new PaletteItemData(
                kind: 'deployment',
                id: $deployment->public_id,
                label: $deployment->serviceInstance->service->name.($deployment->commit_sha === null ? '' : ' · '.substr($deployment->commit_sha, 0, 7)),
                detail: $deployment->environment->name.($deployment->commit_message === null ? '' : ' · '.mb_substr(strtok($deployment->commit_message, "\n") ?: '', 0, 80)),
                projectId: $projectIds[$deployment->environment->project_id] ?? null,
                environmentId: $deployment->environment->public_id,
                status: $deployment->status->value,
            ))->all()),
            servers: array_values($servers->map(static fn (Server $server): PaletteItemData => new PaletteItemData(
                kind: 'server',
                id: $server->public_id,
                label: $server->name,
                status: $server->status->value,
            ))->all()),
            canDeploy: $user->hasTeamPermission($team, Permission::Deploy),
            canWriteVariables: $user->hasTeamPermission($team, Permission::WriteVariables),
        ))->header('Cache-Control', 'no-store');
    }
}
