<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Data\Canvas\EnvironmentData;
use App\Data\Canvas\ProjectAbilitiesData;
use App\Data\Canvas\ProjectData;
use App\Data\Canvas\ProjectLinkData;
use App\Data\Ingress\EdgeOverviewData;
use App\Enums\EnvironmentKind;
use App\Models\Connection;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Queries\EdgeOverview;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Nested route parameters must belong together: bindings already scope each
 * one to the user's teams; this scopes them to each other (anything else is
 * a 404, indistinguishable from a missing id).
 */
final class ProjectScope
{
    public static function environment(Project $project, Environment $environment): Environment
    {
        abort_unless($environment->project_id === $project->id, 404);

        $environment->setRelation('project', $project);

        return $environment;
    }

    /**
     * The service's instance in $environment (including a staged removal).
     */
    public static function instance(Project $project, Environment $environment, Service $service): ServiceInstance
    {
        abort_unless($environment->project_id === $project->id && $service->project_id === $project->id, 404);

        $instance = ServiceInstance::query()
            ->where('environment_id', $environment->id)
            ->where('service_id', $service->id)
            ->first();

        abort_unless($instance instanceof ServiceInstance, 404);

        $instance->setRelation('service', $service);
        $instance->setRelation('environment', $environment);

        return $instance;
    }

    public static function domain(ServiceInstance $instance, Domain $domain): Domain
    {
        abort_unless($domain->service_instance_id === $instance->id, 404);

        return $domain;
    }

    public static function connection(Project $project, Connection $connection): Connection
    {
        abort_unless($connection->project_id === $project->id, 404);

        return $connection;
    }

    /**
     * Shared props of every project page (the top bar).
     *
     * @return array<string, mixed>
     */
    public static function shellProps(Project $project, ?Environment $environment): array
    {
        // Every environment for the switcher (docs/08 §1): production,
        // persistent ones, then previews (newest first, bounded). Loaded at
        // most once, and only when needed: partial reloads (the service
        // panel, realtime refreshes) don't ask for the top bar's lists.
        $loaded = null;
        $environments = static function () use ($project, &$loaded): Collection {
            return $loaded ??= $project->environments()
                ->where('kind', '!=', EnvironmentKind::Preview->value)
                ->orderByRaw("case when kind = 'production' then 0 else 1 end")
                ->orderBy('name')
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
                ->get(['public_id', 'name', 'icon_path', 'icon_version'])
                ->map(static fn (Project $item): ProjectLinkData => new ProjectLinkData($item->public_id, $item->name, $item->iconUrl()))
                ->values()
                ->all(),
            'environments' => static fn (): array => $environments()->map(static fn (Environment $item): EnvironmentData => EnvironmentData::fromEnvironment($item))->values()->all(),
            // Single point of failure badge (docs/02 §5), lazily evaluated.
            'edgeOverview' => static fn (): EdgeOverviewData => resolve(EdgeOverview::class)->for($project->team_id),
            'abilities' => new ProjectAbilitiesData(
                manage: Gate::allows('update', $project),
                connect: Gate::allows('create', [Connection::class, $project]),
                deploy: $current instanceof Environment && Gate::allows('deploy', $current),
            ),
        ];
    }
}
