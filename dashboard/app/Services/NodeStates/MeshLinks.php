<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Data\Protocol\V1\AmbassadorSpec;
use App\Data\Protocol\V1\MeshAllowRule;
use App\Data\Specs\ServiceSpec;
use App\Enums\Mesh\MeshMode;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\WorkloadRole;
use App\Events\NodeStateInvalidated;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Services\Mesh\MeshTopology;
use App\Support\Deployments\EffectiveDeploy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cross-server service links over the mesh (docs/05 §3):
 *
 * - Provider side: a workload consumed from another server gets a
 *   dashboard-allocated `placements.mesh_port` (31000–32999, unique per
 *   server) and is published on its server's mesh IP only (`publishOnMesh`).
 *   "Consumed" is environment-relative, like Compose: every service of an
 *   environment may reach every other by its slug, so a workload is consumed
 *   when another server hosts a placement of its environment; web services
 *   also when the team has another edge (edges route every service and every
 *   immutable URL). The port is sticky: it stays with the placement until the
 *   row is deleted and stays published, so a database is not recreated
 *   whenever its consumers move.
 * - `firewall.meshAllow`: a published port is reachable only from the mesh
 *   IPs of the other servers hosting placements of its environment, plus the
 *   team's other edges for web services. Everything else on the mesh is
 *   dropped by the provider's nftables (WireGuard authenticates the source).
 * - Consumer side: for every service of a local environment without a local
 *   replica but with mesh-published replicas elsewhere, an ambassador
 *   listener `alias:port` on the environment network (alias = service slug)
 *   forwarding to those replicas (serving ones; draining ones only when no
 *   serving one exists).
 *
 * Only with the mesh on and for servers with a mesh IP. A port allocated while
 * rendering another server (a consumer or an edge) re-renders the provider.
 */
final readonly class MeshLinks
{
    public const int FIRST_PORT = 31000;

    public const int LAST_PORT = 32999;

    /** One global allocation lock: renders of different servers never wait on each other in different orders. */
    private const int LOCK_KEY = 0x6C724D50; // "lrMP"

    /** The Tailscale/Headscale CGNAT range launchroomd accepts mesh addresses in. */
    private const string MESH_PATTERN = '/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.\d{1,3}\.\d{1,3}$/D';

    public function __construct(
        private MeshTopology $topology,
    ) {}

    public function active(): bool
    {
        return $this->topology->mode() instanceof MeshMode;
    }

    /**
     * A server's mesh IPv4 if it is a usable mesh address, else null.
     */
    public static function meshIp(?string $ip): ?string
    {
        return is_string($ip) && preg_match(self::MESH_PATTERN, $ip) === 1 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : null;
    }

    public static function validPort(?int $port): bool
    {
        return $port !== null && $port >= self::FIRST_PORT && $port <= self::LAST_PORT;
    }

    /**
     * The mesh links of $server: published ports of its workloads (by
     * workload id), its mesh allow-list and its ambassadors.
     *
     * @param  list<Placement>  $placements  this server's placements
     * @return array{publish: array<string, int>, meshAllow: list<MeshAllowRule>, ambassadors: list<AmbassadorSpec>}
     */
    public function for(Server $server, array $placements): array
    {
        $none = ['publish' => [], 'meshAllow' => [], 'ambassadors' => []];

        if (! $this->active() || self::meshIp($server->mesh_ip) === null || $placements === []) {
            return $none;
        }

        $environmentIds = array_values(array_unique(array_map(static fn (Placement $placement): int => $placement->serviceInstance->environment_id, $placements)));

        /** @var list<Placement> $all */
        $all = array_values(Placement::query()
            ->whereIn('service_instance_id', ServiceInstance::query()->select('id')->whereIn('environment_id', $environmentIds))
            ->with(['server', 'deployment.revision', 'serviceInstance.service', 'serviceInstance.environment'])
            ->orderBy('id')
            ->get()
            ->all());

        $edges = $this->edgeIps($server);

        // Other servers with placements per environment (the consumers).
        /** @var array<int, array<int, string|null>> $peers environment id => server id => mesh ip */
        $peers = [];
        $local = [];

        // A revoked or not yet approved server is nobody's peer.
        $all = array_values(array_filter($all, static fn (Placement $placement): bool => $placement->server_id === $server->id || $placement->server->status->isApproved()));

        foreach ($all as $placement) {
            $environment = $placement->serviceInstance->environment_id;

            if ($placement->server_id === $server->id) {
                if (! $placement->phase->isStopped() || $placement->role === WorkloadRole::Serving) {
                    $local[$placement->service_instance_id] = true;
                }

                continue;
            }

            $peers[$environment][$placement->server_id] = self::meshIp($placement->server->mesh_ip);
        }

        $allocate = [];
        $ambassadorCandidates = [];

        foreach ($all as $placement) {
            $port = self::port($placement);

            if ($port === null) {
                continue;
            }

            if ($placement->server_id === $server->id) {
                $consumed = ($peers[$placement->serviceInstance->environment_id] ?? []) !== [] || (self::isWeb($placement) && $edges !== []);

                if ($consumed && $placement->mesh_port === null) {
                    $allocate[] = $placement;
                }

                continue;
            }

            if (isset($local[$placement->service_instance_id]) || $placement->phase->isStopped() || self::meshIp($placement->server->mesh_ip) === null) {
                continue;
            }

            $ambassadorCandidates[] = $placement;

            if ($placement->mesh_port === null) {
                $allocate[] = $placement;
            }
        }

        $this->allocate($server, $allocate);

        return [
            'publish' => $this->published($server, $all),
            'meshAllow' => $this->allowList($server, $all, $peers, $edges),
            'ambassadors' => $this->ambassadors($ambassadorCandidates),
        ];
    }

    /**
     * Makes sure the web replicas among these placements on other servers
     * (edge upstreams) have a mesh port; their servers re-render to publish
     * it. Other services (databases) are not published for edges.
     *
     * @param  list<Placement>  $placements
     */
    public function ensurePorts(Server $renderer, array $placements): void
    {
        if (! $this->active()) {
            return;
        }

        $this->allocate($renderer, array_values(array_filter($placements, static fn (Placement $placement): bool => $placement->mesh_port === null
            && self::meshIp($placement->server->mesh_ip) !== null && self::isWeb($placement) && self::port($placement) !== null)));
    }

    /**
     * The servers whose NodeState depends on $server's placements in these
     * environments (they host placements there), plus the team's edges.
     *
     * @param  list<string>  $environmentPublicIds
     * @return list<int>
     */
    public function peers(Server $server, array $environmentPublicIds): array
    {
        if (! $this->active()) {
            return [];
        }

        $ids = $environmentPublicIds === [] ? [] : Placement::query()
            ->where('server_id', '!=', $server->id)
            ->whereIn('service_instance_id', ServiceInstance::query()->select('service_instances.id')
                ->join('environments', 'environments.id', '=', 'service_instances.environment_id')
                ->whereIn('environments.public_id', $environmentPublicIds))
            ->distinct()
            ->pluck('server_id')
            ->all();

        $edges = Server::query()
            ->where('team_id', $server->team_id)
            ->where('id', '!=', $server->id)
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
            ->whereJsonContains('roles', ServerRole::Edge->value)
            ->pluck('id')
            ->all();

        $all = array_values(array_unique(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, [...$ids, ...$edges])));
        sort($all);

        return $all;
    }

    /**
     * Allocates the lowest free port per server for placements without one.
     * The lock is taken only when something is missing; the partial unique
     * index (server_id, mesh_port) is the backstop. Providers other than
     * $renderer are re-rendered so they publish the new port.
     *
     * @param  list<Placement>  $placements
     */
    private function allocate(Server $renderer, array $placements): void
    {
        $missing = [];

        foreach ($placements as $placement) {
            if ($placement->mesh_port === null) {
                $missing[$placement->id] = $placement;
            }
        }

        if ($missing === []) {
            return;
        }

        $changed = DB::transaction(function () use ($missing): array {
            DB::select('select pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            $current = Placement::query()->whereKey(array_keys($missing))->pluck('mesh_port', 'id')->all();
            $used = [];

            foreach (Placement::query()->whereIn('server_id', array_unique(array_map(static fn (Placement $p): int => $p->server_id, $missing)))->whereNotNull('mesh_port')->get(['server_id', 'mesh_port']) as $row) {
                $used[$row->server_id][(int) $row->mesh_port] = true;
            }

            $changed = [];

            foreach ($missing as $id => $placement) {
                $have = $current[$id] ?? null;

                if (is_numeric($have)) {
                    // Allocated concurrently by another render.
                    $placement->setRawAttributes([...$placement->getAttributes(), 'mesh_port' => (int) $have], true);

                    continue;
                }

                $port = self::FIRST_PORT;

                while ($port <= self::LAST_PORT && isset($used[$placement->server_id][$port])) {
                    $port++;
                }

                if ($port > self::LAST_PORT) {
                    Log::error('No free mesh-published port left on the server (31000–32999).', ['server' => $placement->server_id, 'workload' => $placement->workload_id]);

                    continue;
                }

                Placement::query()->whereKey($id)->whereNull('mesh_port')->update(['mesh_port' => $port]);
                $used[$placement->server_id][$port] = true;
                $placement->setRawAttributes([...$placement->getAttributes(), 'mesh_port' => $port], true);
                $changed[] = $placement->server_id;
            }

            return $changed;
        });

        $providers = array_values(array_unique(array_filter($changed, static fn (int $id): bool => $id !== $renderer->id)));

        if ($providers !== []) {
            event(new NodeStateInvalidated($providers));
        }
    }

    /**
     * @param  list<Placement>  $all
     * @return array<string, int> workload id => mesh port
     */
    private function published(Server $server, array $all): array
    {
        $publish = [];

        foreach ($all as $placement) {
            if ($placement->server_id === $server->id && self::validPort($placement->mesh_port) && self::port($placement) !== null) {
                $publish[$placement->workload_id] = (int) $placement->mesh_port;
            }
        }

        return $publish;
    }

    /**
     * @param  list<Placement>  $all
     * @param  array<int, array<int, string|null>>  $peers
     * @param  list<string>  $edges
     * @return list<MeshAllowRule>
     */
    private function allowList(Server $server, array $all, array $peers, array $edges): array
    {
        $rules = [];

        foreach ($all as $placement) {
            if ($placement->server_id !== $server->id || ! self::validPort($placement->mesh_port) || self::port($placement) === null) {
                continue;
            }

            $from = array_values(array_filter($peers[$placement->serviceInstance->environment_id] ?? [], is_string(...)));

            if (self::isWeb($placement)) {
                $from = [...$from, ...$edges];
            }

            $from = array_values(array_unique($from));
            sort($from);

            if ($from !== []) {
                $rules[(int) $placement->mesh_port] = new MeshAllowRule((int) $placement->mesh_port, array_slice($from, 0, 256));
            }
        }

        ksort($rules);

        return array_values($rules);
    }

    /**
     * @param  list<Placement>  $candidates  remote replicas of services without a local one
     * @return list<AmbassadorSpec>
     */
    private function ambassadors(array $candidates): array
    {
        /** @var array<int, list<Placement>> $byInstance */
        $byInstance = [];

        foreach ($candidates as $placement) {
            if (self::validPort($placement->mesh_port)) {
                $byInstance[$placement->service_instance_id][] = $placement;
            }
        }

        $listeners = [];

        foreach ($byInstance as $placements) {
            $serving = array_values(array_filter($placements, static fn (Placement $p): bool => $p->role === WorkloadRole::Serving));
            $chosen = $serving !== [] ? $serving : $placements;

            foreach ($chosen as $placement) {
                $spec = self::spec($placement);
                $port = self::port($placement);
                $ip = self::meshIp($placement->server->mesh_ip);

                if (! $spec instanceof ServiceSpec || $port === null || $ip === null) {
                    continue;
                }

                $network = $placement->serviceInstance->environment->networkId();
                $key = $network.'|'.$spec->slug.'|'.$port;
                $listeners[$key] ??= ['network' => $network, 'alias' => $spec->slug, 'port' => $port, 'upstreams' => []];
                $listeners[$key]['upstreams'][] = $ip.':'.$placement->mesh_port;
            }
        }

        ksort($listeners);
        $out = [];

        foreach ($listeners as $listener) {
            $upstreams = array_values(array_unique($listener['upstreams']));
            sort($upstreams);
            $out[] = new AmbassadorSpec($listener['network'], $listener['alias'], $listener['port'], array_slice($upstreams, 0, 64));
        }

        return $out;
    }

    /**
     * Mesh IPs of the team's other approved edges.
     *
     * @return list<string>
     */
    private function edgeIps(Server $server): array
    {
        $ips = [];

        foreach (Server::query()
            ->where('team_id', $server->team_id)
            ->where('id', '!=', $server->id)
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
            ->whereJsonContains('roles', ServerRole::Edge->value)
            ->pluck('mesh_ip')
            ->all() as $ip) {
            $ip = self::meshIp(is_string($ip) ? $ip : null);

            if ($ip !== null) {
                $ips[] = $ip;
            }
        }

        sort($ips);

        return $ips;
    }

    private static function spec(Placement $placement): ?ServiceSpec
    {
        return $placement->deployment->revision?->spec->service($placement->serviceInstance->service->public_id);
    }

    /**
     * The container port of the placement's deployment (null: no port, a worker).
     */
    private static function port(Placement $placement): ?int
    {
        $spec = self::spec($placement);

        return $spec instanceof ServiceSpec ? EffectiveDeploy::for($spec, $placement->deployment)->port : null;
    }

    private static function isWeb(Placement $placement): bool
    {
        return self::spec($placement)?->kind->isWeb() ?? false;
    }
}
