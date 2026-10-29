<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\AdvancedServiceData;
use App\Data\Canvas\CapabilityOptionData;
use App\Data\Canvas\DetectedPlanData;
use App\Data\Canvas\DnsRecordData;
use App\Data\Canvas\DomainData;
use App\Data\Canvas\EdgeServerData;
use App\Data\Canvas\PlacementIssueData;
use App\Data\Canvas\PlacementPreviewServerData;
use App\Data\Canvas\PlacementServerOptionData;
use App\Data\Canvas\PlanStatusData;
use App\Data\Canvas\ScalingPreviewData;
use App\Data\Canvas\ServicePlacementData;
use App\Data\Canvas\ServiceSettingsData;
use App\Data\Canvas\ServiceSourceData;
use App\Data\Canvas\VolumeData;
use App\Data\Canvas\WorkloadData;
use App\Data\ConfigAsCode\ManagedByFileData;
use App\Data\Domains\CertificateData;
use App\Data\Domains\DnsCheckData;
use App\Data\Protocol\V1\NextBuildInfo;
use App\Data\Servers\DeviceData;
use App\Data\Services\DatabaseSource;
use App\Data\Services\GitSource;
use App\Data\Services\ImageSource;
use App\Data\Services\ScalingSettings;
use App\Data\Services\ServicePlanData;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\DomainKind;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\ServiceKind;
use App\Models\ConfigAsCode\EnvironmentConfigFile;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\InstanceSettings;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use App\Scheduling\PlacementFailed;
use App\Scheduling\PlacementRequest;
use App\Scheduling\SchedulableServer;
use App\Scheduling\Scheduler;
use App\Services\Deployments\Placer;
use App\Services\Dns\EdgeAddresses;
use App\Services\Volumes\StagedVolumes;
use App\Support\Servers\ServerLocations;
use Illuminate\Support\Facades\Gate;

/**
 * The Settings tab of a service panel (docs/08 §3.7).
 */
final readonly class ServiceSettings
{
    public function __construct(
        private DatabaseEngines $engines,
        private CurrentEnvironmentSpec $currentSpec,
        private EdgeAddresses $edges,
        private Placer $placer,
        private DockerfileGenerationView $dockerfiles,
    ) {}

    /**
     * @param  EnvironmentSpec|null  $current  the instance environment's current spec, when the caller already built it
     */
    public function for(ServiceInstance $instance, User $user, ?EnvironmentSpec $current = null): ServiceSettingsData
    {
        $instance->loadMissing(['service.parent', 'environment.lastAppliedRevision', 'domains', 'volumes.server', 'activeDeployment']);
        $service = $instance->service;
        $environment = $instance->environment;

        $latestPlanned = Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->whereNotNull('plan')
            ->latest('id')
            ->first();
        $plan = ServicePlanData::forInstance($instance);
        $definedKeys = $this->definedKeys($instance, $current);
        $detected = DetectedPlanData::fromStored($instance->plan['summary'] ?? null, $definedKeys)
            ?? DetectedPlanData::fromStored($latestPlanned?->plan['summary'] ?? null, $definedKeys);

        // Plan previews don't run the build, so the Next.js adapter's report
        // comes from the latest build that produced one.
        if ($detected instanceof DetectedPlanData && ! $detected->next instanceof NextBuildInfo) {
            $detected->next = DetectedPlanData::nextFromStored($latestPlanned?->plan['summary'] ?? null);
            $detected->frameworkVersion ??= $detected->next?->nextVersion;
        }

        $applied = $environment->lastAppliedRevision?->spec->service($service->public_id);
        $appliedHosts = $applied === null ? [] : array_keys($applied->domains);
        $records = $this->edges->records($instance->team_id);
        $gate = Gate::forUser($user);
        $visible = $instance->domains->filter(static fn (Domain $domain): bool => $domain->kind !== DomainKind::Deployment);
        $hostnames = $visible->pluck('hostname')->all();
        $appsDomain = InstanceSettings::current()->apps_domain;
        /** @var Domain|null $generated */
        $generated = $visible->firstWhere('kind', DomainKind::Generated);
        $cnameTarget = is_string($appsDomain) && $appsDomain !== '' && $generated instanceof Domain && str_ends_with($generated->hostname, '.'.$appsDomain) ? $generated->hostname : null;

        $domains = array_values($visible
            // Custom domains first, then generated ones; by hostname within each.
            ->sortBy(static fn (Domain $domain): string => ($domain->kind === DomainKind::Custom ? '0' : '1').$domain->hostname)
            ->map(static function (Domain $domain) use ($records, $appliedHosts, $gate, $hostnames, $cnameTarget): DomainData {
                $custom = $domain->kind === DomainKind::Custom;

                return new DomainData(
                    id: $domain->public_id,
                    hostname: $domain->hostname,
                    kind: $domain->kind,
                    redirectTo: $domain->redirect_to,
                    certificate: CertificateData::fromDomain($domain),
                    dnsRecords: $custom ? array_map(static fn (array $record): DnsRecordData => new DnsRecordData($record[0], $domain->hostname, $record[1]), $records) : [],
                    staged: ! in_array($domain->hostname, $appliedHosts, true),
                    canDelete: $gate->allows('delete', $domain),
                    verification: $custom ? DnsCheckData::fromStored($domain->verification) : null,
                    wwwRedirect: in_array('www.'.$domain->hostname, $hostnames, true),
                    // Pointing at the generated name follows the edges by itself
                    // (docs/05 §5); the UI recommends it, except at a zone apex,
                    // where most DNS hosts allow no CNAME (ALIAS/flattening only).
                    cnameTarget: $custom ? $cnameTarget : null,
                    apex: $custom && substr_count($domain->hostname, '.') < 2,
                    canRedirectWww: $custom && $domain->redirect_to === null && ! str_starts_with($domain->hostname, 'www.'),
                    hsts: $custom && $domain->hsts,
                    canSetHsts: $custom && $domain->redirect_to === null,
                );
            })
            ->all());

        $placements = $instance->active_deployment_id === null ? [] : Placement::query()
            ->where('deployment_id', $instance->active_deployment_id)
            ->with('server:id,name')
            ->orderBy('slot')
            ->get()
            ->map(static fn (Placement $placement): WorkloadData => new WorkloadData($placement->workload_id, $placement->server->name, $placement->phase, $placement->container_id, $placement->restart_count))
            ->all();

        return new ServiceSettingsData(
            source: $this->source($instance),
            detected: $detected,
            plan: $plan instanceof ServicePlanData ? PlanStatusData::fromPlan($plan) : null,
            defaultRailpackVersion: config()->string('launchroom.builds.railpack_version'),
            build: $instance->build,
            deploy: $instance->deploy,
            scaling: $instance->scaling,
            enabled: $instance->enabled,
            domains: $domains,
            privateHost: $service->slug,
            volumes: $this->volumes($instance),
            advanced: new AdvancedServiceData(
                serviceId: $service->public_id,
                instanceId: $instance->public_id,
                environmentId: $environment->public_id,
                networkName: $environment->networkName(),
                imageRepository: $service->kind->isBuilt() ? $service->imageRepository() : null,
                image: $instance->activeDeployment?->image,
                activeDeploymentId: $instance->activeDeployment?->public_id,
                workloads: array_values($placements),
                capabilityOptions: $service->kind === ServiceKind::Database ? [] : CapabilityOptionData::options(),
            ),
            appsDomain: $appsDomain,
            placement: $this->placement($instance),
            edges: $this->edges($instance->team_id),
            configFile: ManagedByFileData::for(EnvironmentConfigFile::query()->where('environment_id', $environment->id)->first(), $service->public_id),
            dockerfileGeneration: $service->kind === ServiceKind::App ? $this->dockerfiles->panel($instance, $user) : null,
        );
    }

    /**
     * The volumes now, then the ones removed with the next deploy (docs/14
     * §9.12), each with the path the running deployment uses when a move
     * to another path is staged.
     *
     * @return list<VolumeData>
     */
    private function volumes(ServiceInstance $instance): array
    {
        $applied = StagedVolumes::appliedPaths($instance);
        $removing = StagedVolumes::pendingRemovals($instance)->load('server');

        return array_values($instance->volumes->concat($removing)->map(static fn (Volume $volume): VolumeData => new VolumeData(
            id: $volume->public_id,
            mountPath: $volume->mount_path,
            sizeBytes: $volume->size_bytes,
            server: $volume->server?->name,
            dockerName: $volume->dockerName(),
            serverId: $volume->server?->public_id,
            serverDevice: $volume->server instanceof Server ? DeviceData::fromServer($volume->server) : null,
            serverStatus: $volume->server?->status,
            removing: $volume->removed_at !== null,
            deployedMountPath: isset($applied[$volume->public_id]) && $applied[$volume->public_id] !== $volume->mount_path ? $applied[$volume->public_id] : null,
        ))->all());
    }

    /**
     * @return list<EdgeServerData>
     */
    private function edges(int $teamId): array
    {
        return array_values(Server::query()
            ->where('team_id', $teamId)
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value])
            ->whereJsonContains('roles', ServerRole::Edge->value)
            ->orderByDesc('is_control_plane')
            ->orderBy('id')
            ->get()
            ->map(static fn (Server $server): EdgeServerData => new EdgeServerData($server->public_id, $server->name, $server->status, DeviceData::fromServer($server)))
            ->all());
    }

    private function placement(ServiceInstance $instance): ServicePlacementData
    {
        $request = $this->placer->request($instance, $instance->scaling, $instance->activeDeployment, $instance->scaling->replicas);
        $views = $this->placer->servers($instance->team_id, $instance->id);
        [$memoryOvercommit, $cpuOvercommit] = $this->placer->overcommit();
        [$planned, $planError] = $this->plan($views, $request);
        $servers = Server::query()->where('team_id', $instance->team_id)->get()->keyBy('id');
        $replicas = $instance->active_deployment_id === null ? [] : Placement::query()
            ->where('deployment_id', $instance->active_deployment_id)
            ->groupBy('server_id')
            ->selectRaw('server_id, count(*) as replicas')
            ->pluck('replicas', 'server_id')
            ->all();
        $options = [];

        foreach ($views as $view) {
            $server = $servers->get($view->id);

            if (! $server instanceof Server || ! $view->hasRole(ServerRole::Runtime)) {
                continue;
            }

            $reason = Scheduler::rejection($view, $request);
            $options[] = new PlacementServerOptionData(
                id: $server->public_id,
                name: $server->name,
                status: $server->status,
                labels: $server->labels,
                platform: $view->platform,
                eligible: $reason === null,
                reason: $reason === '' ? null : $reason,
                replicas: is_numeric($replicas[$view->id] ?? null) ? (int) $replicas[$view->id] : 0,
                planned: $planned[$view->id] ?? 0,
                device: DeviceData::fromServer($server),
                location: ServerLocations::for($server),
                cpus: $view->cpus,
                memoryMb: $view->memoryMb,
                reservedMemoryMb: $view->reservedMemoryMb,
                allocatedMemoryMb: $view->allocatedMemoryMb,
                allocatedCpus: $view->allocatedCpus,
                allowPreviews: $server->allow_previews,
                allowProduction: $server->allow_production,
            );
        }

        $pinned = $request->pinnedServerId === null ? null : $servers->get($request->pinnedServerId);

        return new ServicePlacementData(
            servers: $options,
            issue: PlacementIssueData::fromStored($instance->placement_issue),
            environmentKind: $instance->environment->kind->value,
            planError: $planError,
            stateful: $request->stateful,
            pinnedServer: $pinned instanceof Server ? $pinned->name : null,
            memoryOvercommit: $memoryOvercommit,
            cpuOvercommit: $cpuOvercommit,
        );
    }

    /**
     * What the scheduler would do with draft scaling settings: nothing is
     * placed, pinned or saved (Settings → Scaling's live preview).
     */
    public function preview(ServiceInstance $instance, ScalingSettings $scaling): ScalingPreviewData
    {
        $instance->loadMissing(['activeDeployment']);
        $request = $this->placer->request($instance, $scaling, $instance->activeDeployment, $scaling->replicas);
        $views = $this->placer->servers($instance->team_id, $instance->id);
        [$planned, $error] = $this->plan($views, $request);
        $ids = Server::query()->where('team_id', $instance->team_id)->pluck('public_id', 'id');
        $servers = [];

        foreach ($views as $view) {
            $publicId = $ids->get($view->id);

            if (! is_string($publicId) || ! $view->hasRole(ServerRole::Runtime)) {
                continue;
            }

            $reason = Scheduler::rejection($view, $request);
            $servers[] = new PlacementPreviewServerData($publicId, $reason === null, $reason === '' ? null : $reason, $planned[$view->id] ?? 0);
        }

        return new ScalingPreviewData($servers, $error);
    }

    /**
     * @param  list<SchedulableServer>  $views
     * @return array{array<int, int>, ?string} replicas per server id, or why it doesn't fit
     */
    private function plan(array $views, PlacementRequest $request): array
    {
        try {
            $plan = Scheduler::place($views, $request, ...$this->placer->overcommit());
        } catch (PlacementFailed $failed) {
            return [[], $failed->getMessage()];
        }

        $counts = [];

        foreach ($plan->serverIds as $id) {
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return [$counts, null];
    }

    /**
     * Variable keys the service sees in this environment's intent (for
     * "referenced in code but not defined").
     *
     * @return list<string>
     */
    private function definedKeys(ServiceInstance $instance, ?EnvironmentSpec $current): array
    {
        $environment = $current ?? $this->currentSpec->get($instance->environment);
        $service = $environment->service($instance->service->public_id);

        if (! $service instanceof ServiceSpec) {
            return [];
        }

        $keys = [];

        foreach ([...$service->variables, ...$service->connectionVariables] as $variable) {
            $keys[] = $variable->key;
        }

        foreach ($service->sharedVariableIds as $id) {
            if (isset($environment->sharedVariables[$id])) {
                $keys[] = $environment->sharedVariables[$id]->key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function source(ServiceInstance $instance): ServiceSourceData
    {
        $service = $instance->service;
        $source = $service->source;
        $environmentBranch = $instance->environment->triggers->branch;

        return match (true) {
            $source instanceof GitSource => new ServiceSourceData(
                type: 'git',
                repository: $source->fullName,
                rootDirectory: $source->rootDirectory,
                branch: $instance->branch_override ?? $environmentBranch,
                branchOverride: $instance->branch_override,
                environmentBranch: $environmentBranch,
                parentName: $service->parent?->name,
                provider: $source->isUnconnectedPlainGit() ? 'git' : $source->provider,
            ),
            $source instanceof ImageSource => new ServiceSourceData(type: 'image', image: $source->reference),
            $source instanceof DatabaseSource => new ServiceSourceData(
                type: 'database',
                engine: $source->engine,
                version: $source->version,
                versions: $this->engines->for($source->engine)->versions(),
            ),
            default => new ServiceSourceData(type: $source->type()),
        };
    }
}
