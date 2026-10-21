<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Models\Placement;

/**
 * Cross-server route upstreams (docs/05 §3–4): an edge reaches a replica on
 * another server at `<that server's mesh IPv4>:<placement mesh_port>`, the
 * workload's port published on the mesh IP only.
 *
 * `placements.mesh_port` (31000–32999, unique per server) is allocated by
 * MeshLinks, which also sets the provider's `WorkloadSpec.publishOnMesh` and
 * its nftables allow-list for the edges. Without one (or without a mesh IP)
 * edges skip the replica (no route upstream).
 */
final class MeshUpstreams
{
    /** The Tailscale/Headscale CGNAT range launchroomd allows address upstreams in. */
    private const string MESH_PATTERN = '/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.\d{1,3}\.\d{1,3}$/D';

    public function address(Placement $placement): ?string
    {
        $ip = $placement->server->mesh_ip;
        $port = $placement->mesh_port;

        if ($port === null || $port < 31000 || $port > 32999 || ! is_string($ip)
            || preg_match(self::MESH_PATTERN, $ip) !== 1 || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return $ip.':'.$port;
    }
}
