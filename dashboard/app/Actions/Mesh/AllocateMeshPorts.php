<?php

declare(strict_types=1);

namespace App\Actions\Mesh;

use App\Enums\AdvisoryLock;
use App\Models\Placement;
use App\Models\Server;
use App\Services\NodeStates\MeshLinks;
use App\Support\Network\Mesh;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gives every placement that is reached across servers its mesh-published
 * port (`placements.mesh_port`, docs/05 §3), when placements or the mesh
 * change. It runs at the start of every queued render
 * (App\Actions\Servers\PrepareNodeStateRender), after the change committed,
 * never inside the transaction that placed a workload and never while a
 * NodeState renders (renders only read the port and skip a replica without
 * one).
 *
 * A placement needs a port when the mesh is on, its server is approved with
 * a mesh IP, its workload has a container port, and another approved server
 * hosts a placement of its environment (Compose-style: every service of an
 * environment may reach every other) or, for web services, the team has
 * another edge (edges route every service). Ports are sticky: they stay with
 * the placement until the row is deleted. The lowest free port per server is
 * taken under one global lock; the partial unique index
 * (server_id, mesh_port) is the backstop.
 */
final readonly class AllocateMeshPorts
{
    public function __construct(
        private MeshLinks $links,
    ) {}

    /**
     * @param  iterable<int>  $teamIds
     * @return list<int> the servers that got a port and must publish it
     */
    public function handle(iterable $teamIds): array
    {
        $teams = [];

        foreach ($teamIds as $id) {
            $teams[$id] = true;
        }

        if ($teams === [] || ! $this->links->active()) {
            return [];
        }

        $missing = $this->missing(array_keys($teams));

        return $missing === [] ? [] : $this->allocate($missing);
    }

    /**
     * @param  list<int>  $teamIds
     * @return list<Placement>
     */
    private function missing(array $teamIds): array
    {
        /** @var list<Placement> $candidates */
        $candidates = array_values(Placement::query()
            ->whereIn('team_id', $teamIds)
            ->whereNull('mesh_port')
            ->whereIn('server_id', Server::query()->select('id')->approved()->whereNotNull('mesh_ip'))
            ->with(['server', 'deployment.revision', 'serviceInstance.service'])
            ->orderBy('id')
            ->get()
            ->filter(static fn (Placement $placement): bool => Mesh::ip($placement->server->mesh_ip) !== null && MeshLinks::containerPort($placement) !== null)
            ->all());

        if ($candidates === []) {
            return [];
        }

        $environments = array_values(array_unique(array_map(static fn (Placement $placement): int => $placement->serviceInstance->environment_id, $candidates)));

        /** @var array<int, array<int, true>> $hosts environment id => server ids hosting a placement of it */
        $hosts = [];

        foreach (Placement::query()
            ->join('service_instances', 'service_instances.id', '=', 'placements.service_instance_id')
            ->whereIn('service_instances.environment_id', $environments)
            ->whereIn('placements.server_id', Server::query()->select('id')->approved())
            ->distinct()
            ->toBase()
            ->get(['service_instances.environment_id', 'placements.server_id']) as $row) {
            if (is_numeric($row->environment_id ?? null) && is_numeric($row->server_id ?? null)) {
                $hosts[(int) $row->environment_id][(int) $row->server_id] = true;
            }
        }

        /** @var array<int, list<int>> $edges team id => edge server ids */
        $edges = [];

        foreach (Server::query()->whereIn('team_id', $teamIds)->edges()->get(['id', 'team_id']) as $edge) {
            $edges[$edge->team_id][] = $edge->id;
        }

        return array_values(array_filter($candidates, static function (Placement $placement) use ($hosts, $edges): bool {
            $others = array_diff(array_keys($hosts[$placement->serviceInstance->environment_id] ?? []), [$placement->server_id]);

            return $others !== []
                || (MeshLinks::isWeb($placement) && array_diff($edges[$placement->team_id] ?? [], [$placement->server_id]) !== []);
        }));
    }

    /**
     * @param  list<Placement>  $missing
     * @return list<int> the servers that got a port
     */
    private function allocate(array $missing): array
    {
        return DB::transaction(static function () use ($missing): array {
            AdvisoryLock::MeshPorts->holdForTransaction();

            $servers = array_values(array_unique(array_map(static fn (Placement $placement): int => $placement->server_id, $missing)));
            $used = [];

            foreach (Placement::query()->whereIn('server_id', $servers)->whereNotNull('mesh_port')->get(['server_id', 'mesh_port']) as $row) {
                $used[$row->server_id][(int) $row->mesh_port] = true;
            }

            $changed = [];

            foreach ($missing as $placement) {
                $port = Mesh::FIRST_PORT;

                while ($port <= Mesh::LAST_PORT && isset($used[$placement->server_id][$port])) {
                    $port++;
                }

                if ($port > Mesh::LAST_PORT) {
                    Log::error('No free mesh-published port left on the server ('.Mesh::FIRST_PORT.'–'.Mesh::LAST_PORT.').', ['server' => $placement->server_id, 'workload' => $placement->workload_id]);

                    continue;
                }

                // Allocated concurrently: the row already has one.
                if (Placement::query()->whereKey($placement->id)->whereNull('mesh_port')->update(['mesh_port' => $port]) === 0) {
                    continue;
                }

                $used[$placement->server_id][$port] = true;
                $changed[$placement->server_id] = true;
            }

            return array_keys($changed);
        });
    }
}
