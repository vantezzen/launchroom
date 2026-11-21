<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Data\Protocol\V1\AmbassadorSpec;
use App\Data\Protocol\V1\HealthSpec;
use App\Data\Protocol\V1\ImageFacts;
use App\Data\Protocol\V1\MeshAllowRule;
use App\Data\Protocol\V1\NetworkSpec;
use App\Data\Protocol\V1\RouteSpec;
use App\Data\Protocol\V1\VolumeSpec;
use App\Data\Protocol\V1\WorkloadSpec;
use App\Data\Services\ConfigFile;
use App\Data\Services\DatabaseSource;
use App\Data\Services\DeploySettings;
use App\Data\Services\HealthcheckSettings;
use App\Data\Services\ScheduleSettings;
use App\Data\Specs\DomainSpec;
use App\Data\Specs\ServiceSpec;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\DaemonTaskPurpose;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\DomainKind;
use App\Enums\EnvironmentKind;
use App\Enums\NodeStateField;
use App\Enums\ServerRole;
use App\Enums\ServiceKind;
use App\Enums\WorkloadCapability;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Models\Backups\WalArchive;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Services\Backups\PointInTimeRecovery;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Holds\Holds;
use App\Services\Holds\MoveHolds;
use App\Services\Ingress\EdgeServers;
use App\Services\Previews\PreviewRoutes;
use App\Services\Previews\PreviewUrls;
use App\Services\Security\WafRoutes;
use App\Support\Crypto\WorkloadEnvSealer;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Ingress\DomainRoute;
use App\Support\Ingress\RouteId;
use App\Support\Protocol\Limits;
use App\Support\Variables\SystemVariables;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 *   port (MeshLinks; skipped while a replica has none). EdgeRouting
 *   then applies DNS-01 wildcard certificates and HSTS.
 * - Lifecycle (docs/04 §8): how a workload stops (stop timeout for every
 *   kind), the probe's Host and expectation (OD3), the liveness restart
 *   (OD2), capabilities for images that start as root (OD10) and the owner
 *   of a fresh volume for images that don't (G76).
 *
 * Queries are fixed in number (eager loading); no N+1 over placements.
 */
final readonly class NodeStateContent
{
    public function __construct(
        private ProjectKeyring $keyring,
        private DatabaseEngines $engines,
        private PreviewUrls $previewUrls,
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
        $links = $this->meshLinks->for($server, array_values($placements->all()));
        $held = Holds::instanceIds();
        // Volumes of other services that workloads here mount too (D73).
        $sharedVolumes = $this->sharedVolumes($server, $placements);
        /** @var array<string, string> $sharedOwners volume id => owner service id */
        $sharedOwners = [];

        foreach ($placements as $placement) {
            // A stopped service runs nothing (D68); held ones wait for their hold to end (docs/14 §5.1).
            if (isset($held[$placement->service_instance_id]) || $placement->serviceInstance->stopped_at !== null) {
                continue;
            }

            $workload = $this->workload($server, $placement, $previews, $links['publish'][$placement->workload_id] ?? null, $sharedVolumes);

            if (! $workload instanceof WorkloadSpec) {
                continue;
            }

            $workloads[] = $workload;
            $environment = $placement->deployment->environment;
            $networks[$environment->networkId()] ??= NetworkSpec::of($environment);

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

            foreach ($workload->mounts ?? [] as $mount) {
                $volume = $sharedVolumes[$mount['volume']] ?? null;

                if ($volume instanceof Volume && $volume->service_instance_id !== $placement->service_instance_id) {
                    $volumes[$volume->public_id] ??= new VolumeSpec($volume->public_id, $volume->dockerName());
                    $sharedOwners[$volume->public_id] = $volume->serviceInstance->service->public_id;
                }
            }
        }

        // A release command mounts the service's volumes (G62): declared here
        // while it waits or runs, also before the service's first workload.
        foreach ($this->releaseVolumes($server) as $volume) {
            $volumes[$volume->public_id] ??= new VolumeSpec($volume->public_id, $volume->dockerName());
        }

        // A volume mounted by workloads of several services names its owner (D73).
        foreach ($sharedOwners as $id => $owner) {
            $volumes[$id] = new VolumeSpec($id, $volumes[$id]->name, $owner);
        }

        // A service moving here is held while its volumes are copied in: they
        // exist before it runs (docs/14 §13.2).
        foreach (MoveHolds::preparedVolumes($server->id) as $volume) {
            $volumes[$volume->public_id] ??= new VolumeSpec($volume->public_id, $volume->dockerName());
        }

        ksort($volumes);

        return [
            'networks' => array_values($networks),
            'volumes' => array_values($volumes),
            'workloads' => $workloads,
            'routes' => $server->hasRole(ServerRole::Edge) ? $this->waf->apply($this->edgeRouting->apply($this->routes($server, array_values($placements->all()), $workloads, $previews), $server), $server) : [],
            // Only listeners on networks this state declares (a workload that
            // can't be rendered yet doesn't declare its network).
            'ambassadors' => array_values(array_filter($links['ambassadors'], static fn (AmbassadorSpec $ambassador): bool => isset($networks[$ambassador->network]))),
            'meshAllow' => $links['meshAllow'],
        ];
    }

    /**
     * @param  array<string, Volume>  $shared  volumes of other services, by public id ({@see sharedVolumes})
     */
    private function workload(Server $server, Placement $placement, PreviewRoutes $previews, ?int $meshPort, array $shared = []): ?WorkloadSpec
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
        $owner = $source instanceof DatabaseSource ? null : $this->initOwner($deployment->imageFacts());

        // Mount paths come from the deployment's revision, so a changed path
        // or a removal applies with the next deploy (staged, docs/14 §9.12).
        // A volume the revision doesn't know (added after it) keeps its
        // current path; a removed one it doesn't know is left out.
        foreach ($instance->allVolumes as $volume) {
            $recorded = $spec->volumes[$volume->public_id] ?? null;

            if ($volume->server_id !== $server->id || ($recorded === null && $volume->removed_at !== null)) {
                continue;
            }

            $mounts[] = [
                'volume' => $volume->public_id,
                'target' => $recorded->mountPath ?? $volume->mount_path,
                // An image that runs as another user gets a fresh volume it
                // can write to (launchroomd chowns it once while empty).
                ...($owner === null ? [] : ['initOwner' => $owner]),
            ];
        }

        // Volumes of other services (D73), as the revision recorded them. A
        // workload runs only with all of them: one that is missing, belongs
        // to another environment or service, lives on another server, or a
        // server that can't share volumes keeps the workload out (fail
        // closed; placement co-locates them, App\Services\Deployments\Placer).
        foreach ($spec->volumes as $id => $recorded) {
            if ($recorded->sharedFrom === null) {
                continue;
            }

            $volume = $shared[(string) $id] ?? null;

            if (! $volume instanceof Volume
                || $volume->serviceInstance->environment_id !== $instance->environment_id
                || $volume->serviceInstance->service->public_id !== $recorded->sharedFrom
                || $volume->server_id !== $server->id
                || ! FeatureGate::accepts($server, NodeStateField::SharedVolume)) {
                Log::warning('Workload left out: its shared volume is not usable on this server', ['workload' => $placement->workload_id, 'volume' => (string) $id, 'server' => $server->public_id]);

                return null;
            }

            $mounts[] = ['volume' => $volume->public_id, 'target' => $recorded->mountPath];
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

        $stop ??= $this->processStop($server, $spec, $deployment, $source);

        // Scheduled cron services (docs/04 §1): created, started by launchroomd
        // at every due minute, never restarted.
        $schedule = $spec->kind === ServiceKind::Cron && $spec->deploy->schedule instanceof ScheduleSettings && FeatureGate::accepts($server, NodeStateField::WorkloadSchedule)
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
            health: $port === null ? null : $this->health($effective->healthcheck, $spec, $source),
            capabilities: $source instanceof DatabaseSource
                ? $this->engines->for($source->engine)->capabilities()
                : $this->appCapabilities($spec->deploy->capabilities, $deployment),
            mounts: $mounts === [] ? null : $mounts,
            restart: $schedule !== null ? 'no' : 'unless-stopped',
            drainUntil: $placement->role === WorkloadRole::Draining && $placement->drain_until !== null
                ? $placement->drain_until->toImmutable()->utc()->format('Y-m-d\TH:i:s\Z')
                : null,
            publishOnMesh: $port === null ? null : $meshPort,
            shipLogs: $environment->retainsRuntimeLogs(),
            walArchive: $wal['spec'] ?? null,
            walArchiveFingerprint: $wal['fingerprint'] ?? null,
            stop: $stop,
            schedule: $schedule,
            files: $this->files($spec->deploy->files, $source),
        );
    }

    /**
     * Config files (D65); managed databases have none.
     *
     * @param  list<ConfigFile>  $files
     * @return list<array{path: string, content: string}>|null
     */
    private function files(array $files, mixed $source): ?array
    {
        if ($files === [] || $source instanceof DatabaseSource) {
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
            || ! FeatureGate::accepts($server, NodeStateField::WorkloadStop)) {
            return null;
        }

        $driver = $this->engines->for($source->engine);

        return ['signal' => $driver->stopSignal(), 'timeout' => $driver->stopTimeout(), 'noInit' => true];
    }

    /**
     * Every other service stops with its stop signal and timeout (G30): the
     * service's setting, else the build's hint for the process, else 30 s
     * for workers and crons. A web service without a setting keeps
     * launchroomd's default (10 s, nothing rendered, so its container
     * isn't recreated). Init stays on: it forwards the signal.
     *
     * @return array{signal: string, timeout: string}|null
     */
    private function processStop(Server $server, ServiceSpec $spec, Deployment $deployment, mixed $source): ?array
    {
        if ($source instanceof DatabaseSource || ! FeatureGate::accepts($server, NodeStateField::WorkloadStop)) {
            return null;
        }

        $deploy = $spec->deploy;
        $timeout = $deploy->stopTimeout($spec->kind, $this->stopHint($spec, $deployment));

        if ($spec->kind->isWeb() && $deploy->stopTimeoutSeconds === null && $deploy->stopSignal === null && $timeout === DeploySettings::STOP_TIMEOUT_WEB) {
            return null;
        }

        return ['signal' => $deploy->stopSignal ?? 'SIGTERM', 'timeout' => "{$timeout}s"];
    }

    /**
     * The build's stop timeout for a worker or cron that runs one of its
     * suggested processes (protocol ProcessSuggestion.stopTimeout: a
     * Laravel queue worker or Celery needs 60 s to finish a job).
     */
    private function stopHint(ServiceSpec $spec, Deployment $deployment): ?int
    {
        if (! $spec->kind->requiresParent()) {
            return null;
        }

        foreach ($deployment->plan->summary->processes ?? [] as $process) {
            if ($process->name === $spec->slug && $process->stopTimeout !== null) {
                $seconds = $this->seconds($process->stopTimeout);

                return $seconds === null ? null : max(1, min(Limits::STOP_TIMEOUT_SECONDS, $seconds));
            }
        }

        return null;
    }

    /**
     * The probe (docs/04 §8):
     * - http probes send the service's primary domain as Host (with
     *   X-Forwarded-Proto: https), so host allow-lists (Django, Rails)
     *   accept it as they accept visitors (OD3);
     * - the implicit `/` accepts any answer below 500, so an app without a
     *   root route deploys; explicit and preset paths keep 2xx-3xx (OD3);
     * - a replica whose check keeps failing is restarted after the
     *   service's restart time, never a database's (OD2).
     *
     * Servers without the features drop `host` and `restartAfter` (FeatureGate).
     */
    private function health(HealthcheckSettings $settings, ServiceSpec $spec, mixed $source): ?HealthSpec
    {
        $health = HealthSpec::fromSettings($settings);

        if (! $health instanceof HealthSpec) {
            return null;
        }

        $http = $health->type === 'http';
        $restartAfter = $source instanceof DatabaseSource ? null : $settings->restartAfter();

        return new HealthSpec(
            $health->type,
            $health->startPeriod,
            $health->interval,
            $health->timeout,
            $health->failureThreshold,
            $health->path,
            $http && $settings->implicitPath() ? '2xx-4xx' : $health->expectStatus,
            $http ? SystemVariables::primaryDomain($spec) : null,
            $restartAfter === null ? null : "{$restartAfter}s",
        );
    }

    /**
     * The owner a fresh volume gets (G76): the image's user when it is a
     * numeric non-root uid (`1000` or `1000:1000`; a user name can't be
     * resolved here, so it gets none). Unknown facts: none.
     *
     * @return array{uid: int, gid: int}|null
     */
    private function initOwner(?ImageFacts $facts): ?array
    {
        if (! $facts instanceof ImageFacts || $facts->startsAsRoot() || preg_match('/^(\d{1,10})(?::(\d{1,10}))?$/D', (string) $facts->user, $m) !== 1) {
            return null;
        }

        $uid = (int) $m[1];
        $gid = isset($m[2]) ? (int) $m[2] : $uid;

        return $uid > 0 && $uid <= 2_147_483_647 && $gid <= 2_147_483_647 ? ['uid' => $uid, 'gid' => $gid] : null;
    }

    /**
     * Volumes the release commands pinned to this server mount (G62,
     * RunOnceSpec.mounts): their service's own volumes, on this server.
     *
     * @return list<Volume>
     */
    private function releaseVolumes(Server $server): array
    {
        $ids = [];

        $tasks = DaemonTask::query()
            ->where('server_id', $server->id)
            ->where('type', DaemonTaskType::RunOnce->value)
            // Release commands and one-off commands (D105) mount the service's volumes.
            ->whereIn('purpose', [DaemonTaskPurpose::Release->value, DaemonTaskPurpose::CommandRun->value])
            ->open()
            ->get(['id', 'spec']);

        foreach ($tasks as $task) {
            $mounts = $task->spec->mounts ?? null;

            foreach (is_array($mounts) ? $mounts : [] as $mount) {
                if (is_object($mount) && is_string($mount->volume ?? null)) {
                    $ids[$mount->volume] = true;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return array_values(Volume::query()
            ->where('team_id', $server->team_id)
            ->where('server_id', $server->id)
            ->whereIn('public_id', array_keys($ids))
            ->orderBy('id')
            ->get()
            ->all());
    }

    private function seconds(string $duration): ?int
    {
        if (preg_match('/^(\d{1,6})(ms|s|m|h)$/D', $duration, $m) !== 1) {
            return null;
        }

        return (int) ceil((int) $m[1] * match ($m[2]) {
            'ms' => 0.001,
            's' => 1,
            'm' => 60,
            default => 3600,
        });
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
            || ! FeatureGate::accepts($server, NodeStateField::WorkloadWalArchive)) {
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
     * (App\Actions\Mesh\AllocateMeshPorts allocates it); replicas
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
        $edges = EdgeServers::ids($server->team_id);

        /** @var array<int, array{instance: ServiceInstance, environment: Environment, spec: ServiceSpec, upstreams: list<array{workload: string, port: int}|array{address: string, server?: string}>, local: bool, remote: bool, servers: array<int, true>}> $byInstance */
        $byInstance = [];
        $skipped = [];

        $remote = $this->remotePlacements($server);

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
            $address = $local ? null : MeshLinks::upstreamAddress($placement);

            if ($local ? ! isset($rendered[$placement->workload_id]) : $address === null) {
                if (! $local) {
                    $skipped[] = $placement->workload_id;
                }

                continue;
            }

            $byInstance[$instance->id] ??= ['instance' => $instance, 'environment' => $instance->environment, 'spec' => $spec, 'upstreams' => [], 'local' => false, 'remote' => false, 'servers' => []];
            // `server` names the replica's server in the request trace (docs/23 §5).
            $byInstance[$instance->id]['upstreams'][] = $local ? ['workload' => $placement->workload_id, 'port' => $port] : ['address' => (string) $address, 'server' => $placement->server->public_id];
            $byInstance[$instance->id][$local ? 'local' : 'remote'] = true;
            $byInstance[$instance->id]['servers'][$placement->server_id] = true;
        }

        $this->warnSkipped($server, $skipped);

        $routes = [];

        foreach ($byInstance as ['instance' => $instance, 'environment' => $environment, 'spec' => $spec, 'upstreams' => $upstreams, 'local' => $hasLocal, 'remote' => $hasRemote, 'servers' => $servers]) {
            $preferLocal = $hasLocal && $hasRemote && array_diff($edges, array_keys($servers)) === [];

            // Previews are protected (docs/06 §5); unprotectable ones aren't routed.
            $protection = $previews->protectionFor($environment);

            if ($protection === false) {
                continue;
            }

            foreach ($this->routeGroups($spec) as [$canonical, $hosts]) {
                $routes[$canonical->hostname] = DomainRoute::serving(
                    RouteId::forDomain($canonical->id),
                    $canonical->hostname,
                    $hosts,
                    onDemand: $canonical->kind === DomainKind::Custom,
                    indexed: $environment->kind === EnvironmentKind::Production,
                    upstreams: $upstreams,
                    protection: $protection,
                    loadBalancing: $preferLocal ? 'prefer_local' : null,
                    service: $instance->public_id,
                );
            }
        }

        // Stopped services (D68): their domains answer with the edge's
        // "stopped" page instead of "no app at this address", also while a
        // Start brings them back (until the new version is routed).
        if (FeatureGate::accepts($server, NodeStateField::RouteStopped)) {
            foreach ($this->stoppedInstances($server, array_keys($byInstance)) as $instance) {
                $spec = $instance->environment->lastAppliedRevision?->spec->service($instance->service->public_id);
                $protection = $previews->protectionFor($instance->environment);

                if ($spec === null || $protection === false) {
                    continue;
                }

                foreach ($this->routeGroups($spec) as [$canonical, $hosts]) {
                    $routes[$canonical->hostname] ??= DomainRoute::stopped(
                        RouteId::forDomain($canonical->id),
                        $canonical->hostname,
                        $hosts,
                        onDemand: $canonical->kind === DomainKind::Custom,
                        indexed: $instance->environment->kind === EnvironmentKind::Production,
                        service: $instance->public_id,
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

        foreach ($previews->deploymentRoutes($placements, $rendered, $remote, MeshLinks::upstreamAddress(...)) as $host => $route) {
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
                    ->unfinished()))
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
            ->whereIn('server_id', Server::query()->select('id')->approved())
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
     * allow-list) plus what its build needs at runtime. An image that starts
     * as root (empty user, root or 0) gets the whole allow-list (OD10):
     * stock images like nginx chown their directories and switch to their
     * own user at start, which fails with every capability dropped.
     * no-new-privileges stays, and Docker's other defaults (KILL, NET_RAW,
     * MKNOD, SETFCAP, …) stay dropped.
     *
     * @param  list<string>  $requested
     * @return list<string>|null
     */
    private function appCapabilities(array $requested, Deployment $deployment): ?array
    {
        $capabilities = array_values(array_intersect($requested, WorkloadCapability::values()));
        $capabilities = array_values(array_unique([
            ...$capabilities,
            ...$deployment->plan?->runtimeCapabilities() ?? [],
            ...($deployment->imageFacts()?->startsAsRoot() ?? false) ? WorkloadCapability::values() : [],
        ]));

        return $capabilities === [] ? null : $capabilities;
    }

    /**
     * The volumes of other services that the placements' revisions mount
     * (shared volumes, D73), by public id, with their owner. Same team only.
     *
     * @param  Collection<int, Placement>  $placements
     * @return array<string, Volume>
     */
    private function sharedVolumes(Server $server, Collection $placements): array
    {
        $ids = [];

        foreach ($placements as $placement) {
            $spec = $placement->deployment->revision?->spec->service($placement->serviceInstance->service->public_id);

            foreach ($spec instanceof ServiceSpec ? $spec->volumes : [] as $id => $volume) {
                if ($volume->sharedFrom !== null) {
                    $ids[(string) $id] = true;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var array<string, Volume> $volumes */
        $volumes = Volume::query()
            ->where('team_id', $server->team_id)
            ->whereIn('public_id', array_keys($ids))
            ->with('serviceInstance.service')
            ->get()
            ->keyBy('public_id')
            ->all();

        return $volumes;
    }
}
