<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Enums\EnvironmentKind;
use App\Enums\Transfers\TransferDirection;
use App\Models\Backups\RestoreRun;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\Moves\ServerMove;
use App\Models\Moves\ServerMoveItem;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewShareLink;
use App\Models\Project;
use App\Models\Security\WafExclusion;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Models\Variable;
use App\Models\Volume;
use Illuminate\Support\Facades\Gate;

/**
 * Nested route parameters (and `?environment=` / `?service=` ids) must belong
 * together. {@see TeamScopedBindings} already scopes each one to the user's
 * teams; this scopes them to each other, for web and API controllers alike.
 * Anything else is a 404, indistinguishable from a missing id.
 *
 * Staged removals: a service whose removal is staged keeps running until the
 * change is applied, and the canvas and its panel still show it (docs/06 §4).
 * So it resolves everywhere like any other service: reads and operational
 * access (logs, deployments, console, backups) work. Requests that would
 * change it (settings, variables, domains, volumes, Deploy/Restart/Stop/Start)
 * go through {@see editable()}, which refuses them with 409 until the removal
 * is applied or discarded; the panel hides those controls for the same reason.
 */
final class RouteScope
{
    public static function environment(Project $project, Environment $environment): Environment
    {
        abort_unless($environment->project_id === $project->id, 404);

        $environment->setRelation('project', $project);

        return $environment;
    }

    /**
     * The service's instance in $environment, including a staged removal.
     */
    public static function instance(Project $project, Environment $environment, Service $service): ServiceInstance
    {
        abort_unless($environment->project_id === $project->id, 404);

        return self::instanceIn($environment, $service);
    }

    /**
     * Like {@see instance()} for routes without a `{project}` (the API).
     */
    public static function instanceIn(Environment $environment, Service $service): ServiceInstance
    {
        abort_unless($service->project_id === $environment->project_id, 404);

        $instance = ServiceInstance::query()
            ->where('environment_id', $environment->id)
            ->where('service_id', $service->id)
            ->first();

        abort_unless($instance instanceof ServiceInstance, 404);

        $instance->setRelation('service', $service);
        $instance->setRelation('environment', $environment);

        return $instance;
    }

    /**
     * A service of the project, bound or by its public id (`?service=`, API bodies).
     */
    public static function service(Project|Environment $within, Service|string $service): Service
    {
        $projectId = $within instanceof Project ? $within->id : $within->project_id;

        if ($service instanceof Service) {
            abort_unless($service->project_id === $projectId, 404);

            return $service;
        }

        return Service::query()->where('project_id', $projectId)->where('public_id', $service)->firstOrFail();
    }

    /**
     * The instance of `?service=` in $environment; null without one.
     */
    public static function serviceInstance(Environment $environment, ?string $service): ?ServiceInstance
    {
        return $service === null ? null : self::instanceIn($environment, self::service($environment, $service));
    }

    /**
     * Refuses changes to a service whose removal is staged (see class docs).
     */
    public static function editable(ServiceInstance $instance): ServiceInstance
    {
        abort_if($instance->removed_at !== null, 409, 'This service is staged for removal.');

        return $instance;
    }

    /**
     * `?environment=` of the project, or its production environment without
     * one; the user must be allowed to view it.
     */
    public static function environmentOrProduction(Project $project, ?string $environment): Environment
    {
        $resolved = $project->environments()
            ->when(
                $environment !== null,
                static fn ($query) => $query->where('public_id', $environment),
                static fn ($query) => $query->where('kind', EnvironmentKind::Production->value),
            )
            ->firstOrFail();

        Gate::authorize('view', $resolved);

        return $resolved;
    }

    public static function deployment(Project $project, Environment $environment, Deployment $deployment): Deployment
    {
        abort_unless($environment->project_id === $project->id && $deployment->environment_id === $environment->id, 404);

        return $deployment;
    }

    public static function variable(Project|Environment $within, Variable $variable): Variable
    {
        abort_unless($variable->project_id === ($within instanceof Project ? $within->id : $within->project_id), 404);

        return $variable;
    }

    public static function domain(ServiceInstance $instance, Domain $domain): Domain
    {
        abort_unless($domain->service_instance_id === $instance->id, 404);

        return $domain;
    }

    public static function domainIn(Environment $environment, Domain $domain): Domain
    {
        $domain->loadMissing('serviceInstance');
        abort_unless($domain->serviceInstance->environment_id === $environment->id, 404);

        return $domain;
    }

    public static function connection(Project $project, Connection $connection): Connection
    {
        abort_unless($connection->project_id === $project->id, 404);

        return $connection;
    }

    /**
     * One of the instance's own volumes, not one whose removal is staged.
     */
    public static function volume(ServiceInstance $instance, Volume $volume): Volume
    {
        abort_unless($volume->service_instance_id === $instance->id && $volume->removed_at === null, 404);

        return $volume;
    }

    /**
     * A volume of any service of $environment (shared volumes, D73).
     */
    public static function environmentVolume(Environment $environment, Volume $volume): Volume
    {
        abort_unless(ServiceInstance::query()->whereKey($volume->service_instance_id)->where('environment_id', $environment->id)->exists(), 404);

        return $volume;
    }

    /**
     * A move of $server's services (the server is the move's source).
     */
    public static function move(Server $server, ServerMove $move): ServerMove
    {
        abort_unless($move->source_server_id === $server->id, 404);

        return $move;
    }

    public static function moveItem(ServerMove $move, ServerMoveItem $item): ServerMoveItem
    {
        abort_unless($item->server_move_id === $move->id, 404);

        return $item;
    }

    public static function revision(Environment $environment, EnvironmentRevision $revision): EnvironmentRevision
    {
        abort_unless($revision->environment_id === $environment->id, 404);

        return $revision;
    }

    public static function wafExclusion(ServiceInstance $instance, WafExclusion $exclusion): WafExclusion
    {
        abort_unless($exclusion->service_instance_id === $instance->id, 404);

        return $exclusion;
    }

    public static function importRun(ServiceInstance $instance, DataImportRun $run): DataImportRun
    {
        abort_unless($run->service_instance_id === $instance->id, 404);

        return $run;
    }

    public static function exportRun(ServiceInstance $instance, DataExportRun $run): DataExportRun
    {
        abort_unless($run->service_instance_id === $instance->id, 404);

        return $run;
    }

    public static function preview(Project $project, Preview $preview): Preview
    {
        abort_unless($preview->project_id === $project->id, 404);

        return $preview;
    }

    public static function shareLink(Preview $preview, PreviewShareLink $shareLink): PreviewShareLink
    {
        abort_unless($shareLink->preview_id === $preview->id, 404);

        return $shareLink;
    }

    /**
     * A restore whose source is $instance (the restored copy swaps in for it).
     */
    public static function restore(ServiceInstance $instance, RestoreRun $restore): RestoreRun
    {
        abort_unless($restore->source_service_instance_id === $instance->id, 404);

        return $restore;
    }

    public static function generation(ServiceInstance $instance, DockerfileGeneration $generation): DockerfileGeneration
    {
        abort_unless($generation->service_instance_id === $instance->id, 404);

        return $generation;
    }

    /**
     * Chunk and cancel routes only exist for uploads; downloads are read
     * through their signed link.
     */
    public static function upload(DataTransfer $transfer): DataTransfer
    {
        abort_unless($transfer->direction === TransferDirection::Upload, 404);

        return $transfer;
    }
}
