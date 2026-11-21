<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\EnvironmentData;
use App\Data\Canvas\EnvironmentLiveStatusData;
use App\Data\Canvas\ProjectAbilitiesData;
use App\Data\Canvas\ProjectData;
use App\Data\Canvas\ProjectLinkData;
use App\Data\Canvas\ProjectShellPropsData;
use App\Data\Ingress\EdgeOverviewData;
use App\Enums\EnvironmentKind;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Models\Connection;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Sessions\InteractiveSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Shared props of every project page (the top bar).
 */
final class ProjectShell
{
    /**
     * The keys and value types are {@see ProjectShellPropsData}; closures keep partial reloads from computing them.
     *
     * @return array<string, mixed>
     */
    public static function props(Project $project, ?Environment $environment): array
    {
        // Every environment for the switcher (docs/08 §1): production,
        // persistent ones, then previews (newest first, bounded).
        $loaded = null;
        $environments = static function () use ($project, &$loaded): Collection {
            return $loaded ??= $project->environments()
                ->where('kind', '!=', EnvironmentKind::Preview->value)
                ->inDisplayOrder()
                ->get()
                ->concat($project->environments()
                    ->where('kind', EnvironmentKind::Preview->value)
                    ->latest('id')
                    ->limit(20)
                    ->get());
        };

        $current = $environment ?? $environments()->first();

        return [
            'project' => ProjectData::fromProject($project),
            'environment' => $current instanceof Environment ? EnvironmentData::fromEnvironment($current) : null,
            'projects' => static fn (): array => Project::query()
                ->where('team_id', $project->team_id)
                ->orderBy('name')
                ->limit(50)
                ->select(['id', 'public_id', 'name', 'icon_path', 'icon_version'])
                // The switcher links straight to the production canvas (no redirect hop).
                ->addSelect(['production_environment' => Environment::query()
                    ->select('public_id')
                    ->whereColumn('environments.project_id', 'projects.id')
                    ->where('kind', EnvironmentKind::Production->value)
                    ->limit(1)])
                ->get()
                ->map(static function (Project $item): ProjectLinkData {
                    $production = $item->getAttribute('production_environment');

                    return new ProjectLinkData($item->public_id, $item->name, $item->iconUrl(), is_string($production) ? $production : null);
                })
                ->values()
                ->all(),
            'projectStatuses' => Inertia::optional(static fn (): array => resolve(ProjectStatuses::class)->forTeam($project->team_id, $current instanceof Environment ? $current : null)),
            // Every status surface reads the live-status store this seeds (D108).
            'liveStatus' => Inertia::defer(static fn (): ?EnvironmentLiveStatusData => $current instanceof Environment ? resolve(EnvironmentLiveStatus::class)->for($current) : null),
            'environmentStatuses' => Inertia::optional(static fn (): array => resolve(EnvironmentStatuses::class)->of($environments())),
            'environments' => static fn (): array => $environments()->map(static fn (Environment $item): EnvironmentData => EnvironmentData::fromEnvironment($item))->values()->all(),
            'edgeOverview' => static fn (): EdgeOverviewData => resolve(EdgeOverview::class)->for($project->team_id),
            'abilities' => new ProjectAbilitiesData(
                manage: Gate::allows('update', $project),
                connect: Gate::allows('create', [Connection::class, $project]),
                deploy: $current instanceof Environment && Gate::allows('deploy', $current),
                console: Gate::allows('viewAny', [InteractiveSession::class, $project, InteractiveSessionKind::Console]),
            ),
        ];
    }
}
