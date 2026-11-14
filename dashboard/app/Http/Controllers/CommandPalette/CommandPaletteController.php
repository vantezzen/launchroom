<?php

declare(strict_types=1);

namespace App\Http\Controllers\CommandPalette;

use App\Data\Canvas\NodeStatusData;
use App\Data\CommandPalette\PaletteData;
use App\Data\CommandPalette\PaletteItemData;
use App\Enums\EnvironmentKind;
use App\Enums\Permission;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Queries\ServiceStatusIndex;
use App\Support\Canvas\NodeStatus;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The ⌘K index of the current team (docs/08 §8): projects, environments,
 * services, recent deployments and servers. Names, ids, statuses and live
 * addresses only, never variable values.
 *
 * Services carry the status of production, or of `?environment=` (the page
 * the palette is opened on) for that environment's project, so the
 * project's services can be listed first with the status their nodes show.
 * Projects carry the aggregate status of that same environment (the project
 * switcher shows it).
 */
final class CommandPaletteController extends Controller
{
    private const int RECENT_DEPLOYMENTS = 30;

    public function __invoke(Request $request, #[CurrentUser] User $user, ServiceStatusIndex $statuses): JsonResponse
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
            ->get(['id', 'public_id', 'name', 'project_id', 'kind', 'team_id']);

        /** @var array<int, Environment> $shown project id => the environment whose statuses are shown */
        $shown = $environments
            ->filter(static fn (Environment $environment): bool => $environment->kind === EnvironmentKind::Production)
            ->keyBy('project_id')
            ->all();
        $context = $this->contextEnvironment($request, $environments->all());

        if ($context instanceof Environment) {
            $shown[$context->project_id] = $context;
        }

        $index = $statuses->in(array_values(array_map(static fn (Environment $environment): int => $environment->id, $shown)));

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
            );
        }

        return response()->json(new PaletteData(
            projects: array_values($projects->map(static function (Project $project) use ($shown, $index): PaletteItemData {
                $environment = $shown[$project->id] ?? null;
                $entries = $environment instanceof Environment ? array_values($index[$environment->id] ?? []) : [];

                return new PaletteItemData(
                    kind: 'project',
                    id: $project->public_id,
                    label: $project->name,
                    projectId: $project->public_id,
                    environmentId: $environment?->public_id,
                    iconUrl: $project->iconUrl(),
                    nodeStatus: $environment instanceof Environment ? NodeStatus::aggregate(array_map(static fn (array $entry): NodeStatusData => $entry['status'], $entries)) : null,
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
            ))->all()),
            servers: array_values($servers->map(static fn (Server $server): PaletteItemData => new PaletteItemData(
                kind: 'server',
                id: $server->public_id,
                label: $server->name,
                status: $server->status->value,
            ))->all()),
            canDeploy: $user->hasTeamPermission($team, Permission::Deploy),
            canWriteVariables: $user->hasTeamPermission($team, Permission::WriteVariables),
            canData: $user->hasTeamPermission($team, InteractiveSessionKind::Data->permission()),
        ))->header('Cache-Control', 'no-store');
    }

    /**
     * `?environment=env_…`, only when it is one of the team's (already
     * loaded through the team) and the user may view it.
     *
     * @param  array<int, Environment>  $environments  the team’s environments
     */
    private function contextEnvironment(Request $request, array $environments): ?Environment
    {
        $id = $request->query('environment');

        if (! is_string($id) || preg_match('/^env_[0-9A-Z]{26}$/', $id) !== 1) {
            return null;
        }

        foreach ($environments as $environment) {
            if ($environment->public_id === $id) {
                return Gate::allows('view', $environment) ? $environment : null;
            }
        }

        return null;
    }
}
