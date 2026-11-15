<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Data\Protocol\V1\AmbassadorSpec;
use App\Data\Protocol\V1\MeshAllowRule;
use App\Data\Specs\ServiceSpec;
use App\Enums\Mesh\MeshMode;
use App\Enums\WorkloadRole;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Services\Mesh\MeshTopology;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Network\Mesh;

/**
 * Cross-server service links over the mesh (docs/05 §3):
 *
 * - Provider side: a workload consumed from another server has a
 *   `placements.mesh_port` (App\Actions\Mesh\AllocateMeshPorts) and is
 *   published on its server's mesh IP only (`publishOnMesh`). The port is
 *   sticky: it stays published, so a database is not recreated whenever its
 *   consumers move.
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
 * Only with the mesh on and for servers with a mesh IP. Reads only: a
 * placement without a port yet is neither published nor an upstream.
 */
final readonly class MeshLinks
{
    public function __construct(
        private MeshTopology $topology,
    ) {}

    public function active(): bool
    {
        return $this->topology->mode() instanceof MeshMode;
    }

    /**
     * Where an edge reaches a replica on another server (docs/05 §3–4):
     * `<its server's mesh IPv4>:<mesh_port>`; null without either.
     */
    public static function upstreamAddress(Placement $placement): ?string
    {
        return Mesh::publishedAddress($placement->server->mesh_ip, $placement->mesh_port);
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

        if (! $this->active() || Mesh::ip($server->mesh_ip) === null || $placements === []) {
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

            $peers[$environment][$placement->server_id] = Mesh::ip($placement->server->mesh_ip);
        }

        $ambassadorCandidates = [];

        foreach ($all as $placement) {
            if ($placement->server_id === $server->id || self::containerPort($placement) === null
                || isset($local[$placement->service_instance_id]) || $placement->phase->isStopped() || Mesh::ip($placement->server->mesh_ip) === null) {
                continue;
            }

            $ambassadorCandidates[] = $placement;
        }

        return [
            'publish' => $this->published($server, $all),
            'meshAllow' => $this->allowList($server, $all, $peers, $edges),
            'ambassadors' => $this->ambassadors($ambassadorCandidates),
        ];
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
            ->edges()
            ->pluck('id')
            ->all();

        $all = array_values(array_unique(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, [...$ids, ...$edges])));
        sort($all);

        return $all;
    }

    /**
     * @param  list<Placement>  $all
     * @return array<string, int> workload id => mesh port
     */
    private function published(Server $server, array $all): array
    {
        $publish = [];

        foreach ($all as $placement) {
            if ($placement->server_id === $server->id && Mesh::isPublishedPort($placement->mesh_port) && self::containerPort($placement) !== null) {
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
            if ($placement->server_id !== $server->id || ! Mesh::isPublishedPort($placement->mesh_port) || self::containerPort($placement) === null) {
                continue;
            }

            $from = array_values(array_filter($peers[$placement->serviceInstance->environment_id] ?? [], is_string(...)));

            if (self::isWeb($placement)) {
                $from = [...$from, ...$edges];
            }

            $from = array_values(array_unique($from));
            sort($from);

            if ($from !== []) {
                $rules[(int) $placement->mesh_port] = new MeshAllowRule((int) $placement->mesh_port, array_slice($from, 0, Mesh::MAX_ALLOW_FROM));
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
            if (Mesh::isPublishedPort($placement->mesh_port)) {
                $byInstance[$placement->service_instance_id][] = $placement;
            }
        }

        $listeners = [];

        foreach ($byInstance as $placements) {
            $serving = array_values(array_filter($placements, static fn (Placement $p): bool => $p->role === WorkloadRole::Serving));
            $chosen = $serving !== [] ? $serving : $placements;

            foreach ($chosen as $placement) {
                $spec = self::spec($placement);
                $port = self::containerPort($placement);
                $ip = Mesh::ip($placement->server->mesh_ip);

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
            $out[] = new AmbassadorSpec($listener['network'], $listener['alias'], $listener['port'], array_slice($upstreams, 0, Mesh::MAX_UPSTREAMS));
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
            ->edges()
            ->pluck('mesh_ip')
            ->all() as $ip) {
            $ip = Mesh::ip(is_string($ip) ? $ip : null);

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
    public static function containerPort(Placement $placement): ?int
    {
        $spec = self::spec($placement);

        return $spec instanceof ServiceSpec ? EffectiveDeploy::for($spec, $placement->deployment)->port : null;
    }

    public static function isWeb(Placement $placement): bool
    {
        return self::spec($placement)?->kind->isWeb() ?? false;
    }
}
