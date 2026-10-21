<?php

declare(strict_types=1);

namespace App\Actions\Mesh;

use App\Enums\Mesh\MeshCredentialPurpose;
use App\Enums\Mesh\MeshMode;
use App\Events\NodeStateInvalidated;
use App\Jobs\Mesh\MaintainMeshJob;
use App\Models\Server;
use App\Queries\MeshServers;
use App\Services\Mesh\MeshSecrets;
use App\Services\Mesh\MeshTopology;
use App\Services\NodeStates\MeshLinks;
use App\Support\Servers\MeshStatusFacts;
use Illuminate\Support\Facades\Log;

/**
 * Stores a server's reported mesh state (Report.host.mesh, whitelisted).
 * Runs inside RecordReport's transaction on the locked server row; side
 * effects are after commit.
 *
 * The reported address is a claim, not a fact (review M4):
 *
 * - built-in Headscale: the server's mesh addresses and node come only from
 *   Headscale, linked by the pre-auth key the server registered with
 *   ({@see MaintainMesh}); a connected report whose address differs from
 *   the linked one only asks for maintenance (which drops the key, links
 *   the node, checks tags and re-renders);
 * - your own Tailscale (no API to ask): the address is taken from the
 *   report, but only inside 100.64.0.0/10 and only if no other server has
 *   it; when it changes, every server's mesh addresses re-render.
 *
 * @param  array<string, mixed>|null  $mesh  validated Report.host.mesh (null: the mesh client is off)
 */
final readonly class RecordMeshStatus
{
    public function __construct(
        private MeshSecrets $secrets,
        private MeshTopology $topology,
    ) {}

    /**
     * @param  array<string, mixed>|null  $mesh
     */
    public function handle(Server $server, ?array $mesh): void
    {
        if ($mesh === null) {
            if ($server->mesh_status !== null) {
                $server->forceFill(['mesh_status' => null, 'mesh_status_at' => now()]);
            }

            return;
        }

        $facts = MeshStatusFacts::fromProtocol($mesh);
        $server->forceFill(['mesh_status' => $facts, 'mesh_status_at' => now()]);

        if ($facts['state'] !== 'connected' || $facts['ipv4'] === null) {
            return;
        }

        if ($this->topology->mode() === MeshMode::Embedded) {
            if ($server->mesh_node_id === null || $server->mesh_ip !== $facts['ipv4']) {
                dispatch(new MaintainMeshJob)->afterCommit();
            }

            return;
        }

        $ip = MeshLinks::meshIp($facts['ipv4']);
        $taken = $ip === null || Server::query()->whereKeyNot($server->id)->whereNull('revoked_at')->where('mesh_ip', $ip)->exists();

        if ($taken) {
            Log::warning('Mesh address refused: outside the mesh range or another server’s.', ['server' => $server->public_id]);

            return;
        }

        $joined = $server->mesh_ip === null;
        $moved = $server->mesh_ip !== $ip;
        $server->forceFill(['mesh_ip' => $ip, 'mesh_ipv6' => $facts['ipv6']]);

        if ($joined) {
            $this->secrets->forget(MeshSecrets::serverScope($server), MeshCredentialPurpose::PreAuthKey);
        }

        if ($moved) {
            // Its own state (key dropped, published ports), the control
            // plane's registry address, and every mesh address other servers
            // render: ambassador upstreams, allow-lists, edge upstreams and
            // the certificate storage's allowed edges (docs/05 §3, §5).
            event(new NodeStateInvalidated(array_values(array_unique([$server->id, ...MeshServers::ids()]))));
        }
    }
}
