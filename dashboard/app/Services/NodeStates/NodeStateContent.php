<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Data\Protocol\V1\AmbassadorSpec;
use App\Data\Protocol\V1\HealthSpec;
use App\Data\Protocol\V1\MeshAllowRule;
use App\Data\Protocol\V1\NetworkSpec;
use App\Data\Protocol\V1\RouteSpec;
use App\Data\Protocol\V1\VolumeSpec;
use App\Data\Protocol\V1\WorkloadSpec;
use App\Data\Services\ConfigFile;
use App\Data\Services\DatabaseSource;
use App\Data\Services\ScheduleSettings;
use App\Data\Specs\DomainSpec;
use App\Data\Specs\ServiceSpec;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\DaemonFeature;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\DomainKind;
use App\Enums\EnvironmentKind;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\ServiceKind;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Models\Backups\WalArchive;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Services\Backups\PointInTimeRecovery;
use App\Services\Backups\RestoreHolds;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Moves\MoveHolds;
use App\Services\Previews\PreviewRoutes;
use App\Services\Previews\PreviewUrls;
use App\Services\Security\WafRoutes;
use App\Support\Blueprints\BlueprintValidator;
use App\Support\Crypto\WorkloadEnvSealer;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Hostname;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Computes the networks, volumes, workloads and routes of one server's
 * NodeState from its placements (docs/02 §2 step 4).
 *
 * - Workloads come from the deployment's revision (immutable), its image and
 *   its encrypted env snapshot, sealed per (server, workload); build summary
 *   defaults fill unset port/health/start command (EffectiveDeploy).
 * - Routes (edge servers) come from the environment's last applied revision:
 *   domains apply on Deploy without a new deployment. Every edge routes every
 *   service of the team (multi-edge, docs/05 §4): upstreams are this server's
 *   workloads of the active deployment plus draining ones (the daemon prefers
 *   serving ones) and replicas on other servers through their mesh-published
 *   port (MeshUpstreams; skipped while a replica has none). EdgeRouting
 *   then applies DNS-01 wildcard certificates and HSTS.
 *
 * Queries are fixed in number (eager loading); no N+1 over placements.
 */
final readonly class NodeStateContent
{
    /** Stop timeout of managed databases (`WorkloadSpec.stop`, D42). */
    private const string DATABASE_STOP_TIMEOUT = '60s';

    public function __construct(
        private ProjectKeyring $keyring,
        private DatabaseEngines $engines,
        private PreviewUrls $previewUrls,
        private MeshUpstreams $meshUpstreams,
        private EdgeRouting $edgeRouting,
        private MeshLinks $meshLinks,
        private MeshReadiness $meshReadiness,
        private PointInTimeRecovery $pointInTime,
        private WafRoutes $waf,
    ) {}

    /**
     * @return array{networks: list<NetworkSpec>, volumes: list<VolumeSpec>, workloads: list<WorkloadSpec>, routes: list<RouteSpec>, ambassadors: list<AmbassadorSpec>, meshAllow: list<MeshAllowRule>}
     */
    public function for(Server $server): array
    {
        $placements = Placement::query()
            ->where('server_id', $server->id)
            ->with([
                'deployment.revision',
                'deployment.environment.project',
                'serviceInstance.service',
                'serviceInstance.allVolumes',
                'serviceInstance.environment.lastAppliedRevision',
            ])
            ->orderBy('id')
            ->get();

        $networks = [];
        $volumes = [];
        $workloads = [];
        $previews = new PreviewRoutes($this->previewUrls, $server);
        // Cross-server links over the mesh (docs/05 §3): published ports, allow-list, ambassadors.
        $links = $this->meshLinks->for($server, array_values($placements->all()));
        // Restores hold services (volume: the service; database in place: its dependents) until they finished (docs/14 §5.1).
        $held = RestoreHolds::instanceIds();

        foreach ($placements as $placement) {
            // A stopped service runs nothing (D68); held ones wait for a restore.
            if (isset($held[$placement->service_instance_id]) || $placement->serviceInstance->stopped_at !== null) {
                continue;
            }

            $workload = $this->workload($server, $placement, $previews, $links['publish'][$placement->workload_id] ?? null);

            if (! $workload instanceof WorkloadSpec) {
                continue;
            }

            $workloads[] = $workload;
            $environment = $placement->deployment->environment;
            // Keyed by network id: each network is declared once.
            $networks[$environment->networkId()] ??= new NetworkSpec($environment->networkId(), $environment->networkName(), $environment->network_subnet);

            // Previews sharing a base database also join the base network.
            foreach ($placement->deployment->revision === null ? [] : $previews->sharedNetworks($placement->deployment->revision->spec) as $shared) {
                $networks[$shared['network']->id] ??= $shared['network'];
            }

            // Declared when a workload here mounts it (a staged removal stays
            // while the deployment that mounts it runs, docs/14 §9.12).
            foreach ($placement->serviceInstance->allVolumes as $volume) {
                if ($volume->server_id === $server->id && in_array($volume->public_id, array_column($workload->mounts ?? [], 'volume'), true)) {
                    $volumes[$volume->public_id] = new VolumeSpec($volume->public_id, $volume->dockerName());
                }
            }
        }

        // A service moving here is held while its volumes are copied in: they
        // exist before it runs (docs/14 §13.2).
        foreach ($server->supportsDaemonFeature(DaemonFeature::PreparedVolumes) ? MoveHolds::preparedVolumes($server->id) : [] as $volume) {
            $volumes[$volume->public_id] ??= new VolumeSpec($volume->public_id, $volume->dockerName());
        }

        ksort($volumes);

        return [
            'networks' => array_values($networks),
            'volumes' => array_values($volumes),
            'workloads' => $workloads,
            // The web firewall's settings per route (docs/22 §6.3) for edges with the `waf` feature.
            'routes' => $server->hasRole(ServerRole::Edge) ? $this->waf->apply($this->edgeRouting->apply($this->routes($server, array_values($placements->all()), $workloads, $previews), $server), $server) : [],
            // Only listeners on networks this state declares (a workload that
            // can't be rendered yet doesn't declare its network).
            'ambassadors' => array_values(array_filter($links['ambassadors'], static fn (AmbassadorSpec $ambassador): bool => isset($networks[$ambassador->network]))),
            'meshAllow' => $links['meshAllow'],
        ];
    }

    private function workload(Server $server, Placement $placement, PreviewRoutes $previews, ?int $meshPort): ?WorkloadSpec
    {
        $deployment = $placement->deployment;
        $instance = $placement->serviceInstance;
        $spec = $deployment->revision?->spec->service($instance->service->public_id);

        if ($spec === null || $deployment->image === null || $deployment->env_snapshot === null) {
            return null;
        }

        $environment = $deployment->environment;
        $effective = EffectiveDeploy::for($spec, $deployment);
        $env = [...$effective->envDefaults, ...$this->keyring->decryptSnapshot($environment->project, $deployment->public_id, $deployment->env_snapshot)];
        $port = $effective->port;
        $source = $spec->source();

        $mounts = [];

        // Mount paths come from the deployment's revision, so a changed path
        // or a removal applies with the next deploy (staged, docs/14 §9.12).
        // A volume the revision doesn't know (added after it) keeps its
        // current path; a removed one it doesn't know is left out.
        foreach ($instance->allVolumes as $volume) {
            $recorded = $spec->volumes[$volume->public_id] ?? null;

            if ($volume->server_id !== $server->id || ($recorded === null && $volume->removed_at !== null)) {
                continue;
            }

            $mounts[] = ['volume' => $volume->public_id, 'target' => $recorded->mountPath ?? $volume->mount_path];
        }

        $argv = $effective->argv($effective->startCommand);
        $entrypoint = $spec->deploy->entrypoint;

        // An explicit entrypoint (Compose imports, docs/17) replaces the image's; the start command is its args.
        if ($entrypoint !== null && $entrypoint !== [] && ! $source instanceof DatabaseSource) {
            $argv = ['command' => $entrypoint, 'args' => $effective->startCommand];
        }
        $wal = $this->walArchive($server, $placement, $source, $mounts, $argv);
        $stop = $this->databaseStop($server, $source, $argv);

        if ($stop !== null && $source instanceof DatabaseSource) {
            $argv['command'] = $this->engines->for($source->engine)->entrypoint();
        }

        // Scheduled cron services (docs/04 §1): created, started by launchroomd
        // at every due minute, never restarted. A launchroomd without the
        // feature keeps running the command continuously, as before.
        $schedule = $spec->kind === ServiceKind::Cron && $spec->deploy->schedule instanceof ScheduleSettings && $server->supportsDaemonFeature(DaemonFeature::CronSchedule)
            ? ['cron' => $spec->deploy->schedule->cron, 'timeZone' => $spec->deploy->schedule->timeZone]
            : null;

        if ($schedule !== null) {
            $port = null;
        }

        return new WorkloadSpec(
            id: $placement->workload_id,
            service: $instance->service->public_id,
            environment: $environment->public_id,
            deployment: $deployment->public_id,
            image: $deployment->image,
            sealedEnv: WorkloadEnvSealer::seal($server->sealing_public_key, $server->public_id, $placement->workload_id, $env),
            // Stable per deployment and secret-free: a hash of the ciphertext.
            envFingerprint: hash('sha256', $deployment->env_snapshot),
            cpus: $spec->scaling->cpus,
            memoryMb: $spec->scaling->memoryMb,
            pids: $spec->scaling->pids(),
            networks: [
                ['network' => $environment->networkId(), 'aliases' => [$spec->slug]],
                ...array_map(static fn (array $shared): array => ['network' => $shared['network']->id, 'aliases' => $shared['aliases']], $previews->sharedNetworks($deployment->revision->spec)),
            ],
            role: $placement->role->value,
            command: $argv['command'],
            args: $wal['args'] ?? $argv['args'],
            port: $port,
            health: $port === null ? null : HealthSpec::fromSettings($effective->healthcheck),
            capabilities: $source instanceof DatabaseSource
                ? $this->engines->for($source->engine)->capabilities()
                : $this->appCapabilities($spec->deploy->capabilities, $deployment),
            mounts: $mounts === [] ? null : $mounts,
            restart: $schedule !== null ? 'no' : 'unless-stopped',
            drainUntil: $placement->role === WorkloadRole::Draining && $placement->drain_until !== null
                ? $placement->drain_until->toImmutable()->utc()->format('Y-m-d\TH:i:s\Z')
                : null,
            publishOnMesh: $port === null ? null : $meshPort,
            // Runtime log retention (opt-in per environment), only for daemons that can ship.
            shipLogs: $environment->retainsRuntimeLogs() && $server->supportsDaemonFeature(DaemonFeature::ShipLogs),
            walArchive: $wal['spec'] ?? null,
            walArchiveFingerprint: $wal['fingerprint'] ?? null,
            stop: $stop,
            schedule: $schedule,
            files: $this->files($server, $spec->deploy->files, $source),
        );
    }

    /**
     * Config files (D65) for daemons that announced the feature. Managed
     * databases have none; an older launchroomd gets the workload without
     * them (and a warning here), as with the other optional features.
     *
     * @param  list<ConfigFile>  $files
     * @return list<array{path: string, content: string}>|null
     */
    private function files(Server $server, array $files, mixed $source): ?array
    {
        if ($files === [] || $source instanceof DatabaseSource) {
            return null;
        }

        if (! $server->supportsDaemonFeature(DaemonFeature::ConfigFiles)) {
            Log::warning('launchroomd on this server does not support config files; update it', ['server' => $server->public_id]);

            return null;
        }

        return array_map(static fn (ConfigFile $file): array => ['path' => $file->path, 'content' => $file->content], $files);
    }

    /**
     * Managed databases stop with their engine's shutdown signal, a 60 s
     * timeout and no init process (D42): the init runs without CAP_KILL and
     * can't signal a server that dropped to its own user, so Docker would
     * stop it uncleanly. Only with the image's entrypoint (which execs the
     * server as PID 1; or the driver's, when the image's starts an init) and
     * for daemons that announced the feature.
     *
     * @param  array{command: list<string>|null, args: list<string>|null}  $argv
     * @return array{signal: string, timeout: string, noInit: bool}|null
     */
    private function databaseStop(Server $server, mixed $source, array $argv): ?array
    {
        if (! $source instanceof DatabaseSource || $argv['command'] !== null
            || ! $server->supportsDaemonFeature(DaemonFeature::StopSpec)) {
            return null;
        }

        return ['signal' => $this->engines->for($source->engine)->stopSignal(), 'timeout' => self::DATABASE_STOP_TIMEOUT, 'noInit' => true];
    }

    /**
     * Point-in-time recovery (docs/14 §10.2): a serving Postgres workload
     * with an enabled WAL archive archives into its volume (args) and
     * launchroomd ships from there (walArchive). Only for daemons that
     * announced the feature and when the archive directory is in a mount.
     *
     * @param  list<array{volume: string, target: string}>  $mounts
     * @param  array{command: list<string>|null, args: list<string>|null}  $argv
     * @return array{args: list<string>, spec: array<string, mixed>, fingerprint: string}|null
     */
    private function walArchive(Server $server, Placement $placement, mixed $source, array $mounts, array $argv): ?array
    {
        if (! $source instanceof DatabaseSource || $placement->role !== WorkloadRole::Serving || $argv['command'] !== null
            || ! $server->supportsDaemonFeature(DaemonFeature::PointInTime)) {
            return null;
        }

        $archive = $this->pointInTime->enabledFor($placement->serviceInstance);

        if (! $archive instanceof WalArchive) {
            return null;
        }

        $mountPath = $this->pointInTime->mountPath($source->version);
        $args = $this->pointInTime->archivingArgs($argv['args'], $source->version);

        if ($args === null || ! in_array($mountPath, array_column($mounts, 'target'), true)) {
            return null;
        }

        return ['args' => $args, ...$this->pointInTime->walArchiveSpec($archive, $server, $placement->workload_id, $source->version)];
    }

    /**
     * Every edge routes every service of the team (docs/05 §4, multi-edge):
     * replicas on this server are workload upstreams; replicas on other
     * servers are mesh upstreams through their mesh-published port
     * (MeshLinks allocates it, MeshUpstreams renders the address); replicas
     * on servers without a mesh address are skipped.
     * `prefer_local` when every edge of the team runs a replica (each edge
     * then serves from its own; DNS spreads the clients).
     *
     * @param  list<Placement>  $placements  this server's placements
     * @param  list<WorkloadSpec>  $workloads
     * @return list<RouteSpec>
     */
    private function routes(Server $server, array $placements, array $workloads, PreviewRoutes $previews): array
    {
        $rendered = array_flip(array_map(static fn (WorkloadSpec $workload): string => $workload->id, $workloads));
        $edges = $this->teamEdges($server);

        /** @var array<int, array{environment: Environment, spec: ServiceSpec, upstreams: list<array{workload: string, port: int}|array{address: string}>, local: bool, remote: bool, servers: array<int, true>}> $byInstance */
        $byInstance = [];
        $skipped = [];

        $remote = $this->remotePlacements($server);
        // Web replicas elsewhere get a mesh-published port (docs/05 §3); their servers re-render to publish it.
        $this->meshLinks->ensurePorts($server, $remote);

        foreach ([...$placements, ...$remote] as $placement) {
            $instance = $placement->serviceInstance;
            $routed = $placement->role === WorkloadRole::Draining || $placement->deployment_id === $instance->active_deployment_id
                || $this->rollingHealthy($placement);
            $spec = $instance->environment->lastAppliedRevision?->spec->service($instance->service->public_id);
            $port = $spec === null ? null : EffectiveDeploy::for($spec, $placement->deployment)->port;

            if (! $routed || $spec === null || $port === null) {
                continue;
            }

            $local = $placement->server_id === $server->id;
            $address = $local ? null : $this->meshUpstreams->address($placement);

            if ($local ? ! isset($rendered[$placement->workload_id]) : $address === null) {
                if (! $local) {
                    $skipped[] = $placement->workload_id;
                }

                continue;
            }

            $byInstance[$instance->id] ??= ['environment' => $instance->environment, 'spec' => $spec, 'upstreams' => [], 'local' => false, 'remote' => false, 'servers' => []];
            $byInstance[$instance->id]['upstreams'][] = $local ? ['workload' => $placement->workload_id, 'port' => $port] : ['address' => (string) $address];
            $byInstance[$instance->id][$local ? 'local' : 'remote'] = true;
            $byInstance[$instance->id]['servers'][$placement->server_id] = true;
        }

        $this->warnSkipped($server, $skipped);

        $routes = [];

        foreach ($byInstance as ['environment' => $environment, 'spec' => $spec, 'upstreams' => $upstreams, 'local' => $hasLocal, 'remote' => $hasRemote, 'servers' => $servers]) {
            $preferLocal = $hasLocal && $hasRemote && array_diff($edges, array_keys($servers)) === [];

            // Previews are protected (docs/06 §5); unprotectable ones aren't routed.
            $protection = $previews->protectionFor($environment);

            if ($protection === false) {
                continue;
            }

            foreach ($this->routeGroups($spec) as [$canonical, $hosts]) {
                $routes[$canonical->hostname] = new RouteSpec(
                    id: Domain::routeIdFor($canonical->id),
                    hosts: $hosts,
                    tls: match (true) {
                        Hostname::isLocal($canonical->hostname) => 'internal',
                        $canonical->kind === DomainKind::Custom => 'on_demand',
                        default => 'acme',
                    },
                    upstreams: $upstreams,
                    redirectWwwTo: count($hosts) > 1 ? $canonical->hostname : null,
                    // docs/06 §5: noindex on every non-production host.
                    headers: $environment->kind === EnvironmentKind::Production ? null : ['X-Robots-Tag' => 'noindex'],
                    protection: $protection,
                    loadBalancing: $preferLocal ? 'prefer_local' : null,
                );
            }
        }

        // Stopped services (D68): their domains answer with the edge's
        // "stopped" page instead of "no app at this address", also while a
        // Start brings them back (until the new version is routed).
        if ($server->supportsDaemonFeature(DaemonFeature::ServiceStop)) {
            foreach ($this->stoppedInstances($server, array_keys($byInstance)) as $instance) {
                $spec = $instance->environment->lastAppliedRevision?->spec->service($instance->service->public_id);
                $protection = $previews->protectionFor($instance->environment);

                if ($spec === null || $protection === false) {
                    continue;
                }

                foreach ($this->routeGroups($spec) as [$canonical, $hosts]) {
                    $routes[$canonical->hostname] ??= new RouteSpec(
                        id: Domain::routeIdFor($canonical->id),
                        hosts: $hosts,
                        tls: match (true) {
                            Hostname::isLocal($canonical->hostname) => 'internal',
                            $canonical->kind === DomainKind::Custom => 'on_demand',
                            default => 'acme',
                        },
                        upstreams: [],
                        redirectWwwTo: count($hosts) > 1 ? $canonical->hostname : null,
                        headers: $instance->environment->kind === EnvironmentKind::Production ? null : ['X-Robots-Tag' => 'noindex'],
                        stopped: true,
                    );
                }
            }
        }

        // Immutable per-deployment URLs (docs/05 §5); a domain wins a clash.
        $taken = [];

        foreach ($routes as $route) {
            foreach ($route->hosts as $host) {
                $taken[$host] = true;
            }
        }

        foreach ($previews->deploymentRoutes($placements, $rendered, $remote, $this->meshUpstreams->address(...)) as $host => $route) {
            if (! isset($taken[$host])) {
                $routes[$host] = $route;
            }
        }

        ksort($routes);

        return array_values($routes);
    }

    /**
     * The team's stopped instances (D68), and the ones a Start is bringing
     * back that have no routed replica yet ($routed: instances with one).
     *
     * @param  list<int>  $routed
     * @return list<ServiceInstance>
     */
    private function stoppedInstances(Server $server, array $routed): array
    {
        return array_values(ServiceInstance::query()
            ->current()
            ->where('team_id', $server->team_id)
            ->whereNotIn('id', $routed)
            ->where(static fn (Builder $query) => $query
                ->whereNotNull('stopped_at')
                ->orWhereIn('id', Deployment::query()->select('service_instance_id')
                    ->where('trigger', DeploymentTrigger::Start->value)
                    ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight()]))))
            ->with(['service', 'environment.lastAppliedRevision'])
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * Routed placements of the team on other servers (active deployment or
     * draining, observed running; staged `ready` ones for their immutable
     * URLs), with what routes() and deploymentRoutes() need eager-loaded.
     *
     * @return list<Placement>
     */
    private function remotePlacements(Server $server): array
    {
        return array_values(Placement::query()
            ->where('team_id', $server->team_id)
            ->where('server_id', '!=', $server->id)
            // Never a revoked server's replicas (its mesh address isn't trusted anymore).
            ->whereIn('server_id', Server::query()->select('id')->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value]))
            ->whereIn('phase', [WorkloadPhase::Running->value, WorkloadPhase::Healthy->value, WorkloadPhase::Draining->value])
            ->where(static fn (Builder $query) => $query
                ->where('role', WorkloadRole::Draining->value)
                ->orWhereIn('deployment_id', ServiceInstance::query()->select('active_deployment_id')->whereNotNull('active_deployment_id'))
                ->orWhere(static fn (Builder $rolling) => $rolling
                    ->where('phase', WorkloadPhase::Healthy->value)
                    ->whereIn('deployment_id', Deployment::query()->select('id')->where('status', DeploymentStatus::HealthChecking->value)->where('hold_traffic', false)))
                ->orWhereIn('deployment_id', Deployment::query()->select('id')->where('status', DeploymentStatus::Ready->value)))
            ->with(['server', 'deployment.revision', 'deployment.environment.project', 'serviceInstance.service', 'serviceInstance.environment.lastAppliedRevision'])
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * A healthy replica of a deployment still health-checking (and not
     * staged) already receives traffic next to the old version: rolling
     * updates (AdvanceDeployment, max-surge 1) shift traffic replica by
     * replica.
     */
    private function rollingHealthy(Placement $placement): bool
    {
        return $placement->phase === WorkloadPhase::Healthy
            && $placement->deployment->status === DeploymentStatus::HealthChecking
            && ! $placement->deployment->hold_traffic
            && $this->meshReadiness->ready($placement);
    }

    /**
     * Ids of the team's approved edge servers.
     *
     * @return list<int>
     */
    private function teamEdges(Server $server): array
    {
        /** @var list<int> $ids */
        $ids = Server::query()
            ->where('team_id', $server->team_id)
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
            ->whereJsonContains('roles', ServerRole::Edge->value)
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * Replicas on other servers without a mesh address (mesh off, or the
     * server hasn't joined yet) or mesh-published port can't be reached from
     * this edge. Logged once per hour per set.
     *
     * @param  list<string>  $workloads
     */
    private function warnSkipped(Server $server, array $workloads): void
    {
        if ($workloads === []) {
            return;
        }

        sort($workloads);

        if (Cache::add('launchroom:edge-skipped:'.$server->id.':'.hash('xxh128', implode(',', $workloads)), true, 3600)) {
            Log::warning('Edge routes skip replicas on other servers: no mesh address or mesh-published port yet (docs/05 §3).', [
                'server' => $server->public_id,
                'workloads' => $workloads,
            ]);
        }
    }

    /**
     * Groups a service's domains into routes: a domain whose redirect target
     * is another domain of the service joins that route (www ↔ apex).
     *
     * @return list<array{DomainSpec, list<string>}>
     */
    private function routeGroups(ServiceSpec $spec): array
    {
        $groups = [];

        foreach ($spec->domains as $domain) {
            if ($domain->redirectTo === null || ! isset($spec->domains[$domain->redirectTo])) {
                $groups[$domain->hostname] = [$domain, [$domain->hostname]];
            }
        }

        foreach ($spec->domains as $domain) {
            if ($domain->redirectTo !== null && isset($groups[$domain->redirectTo])) {
                $groups[$domain->redirectTo][1][] = $domain->hostname;
            }
        }

        return array_values($groups);
    }

    /**
     * Capabilities of an app workload: the service's own (within the
     * allow-list), plus NET_BIND_SERVICE for images Railpack built with its
     * PHP provider. Their FrankenPHP binary carries that file capability, and
     * with every capability dropped the kernel refuses to exec it ("Operation
     * not permitted"), so the app could never start (docs/04 §3.1).
     *
     * @param  list<string>  $requested
     * @return list<string>|null
     */
    private function appCapabilities(array $requested, Deployment $deployment): ?array
    {
        $capabilities = array_values(array_intersect($requested, BlueprintValidator::CAPABILITIES));
        $summary = $deployment->plan['summary'] ?? null;

        if (is_array($summary) && ($summary['builder'] ?? null) === 'railpack' && ($summary['provider'] ?? null) === 'php' && ! in_array('NET_BIND_SERVICE', $capabilities, true)) {
            $capabilities[] = 'NET_BIND_SERVICE';
        }

        return $capabilities === [] ? null : $capabilities;
    }
}
