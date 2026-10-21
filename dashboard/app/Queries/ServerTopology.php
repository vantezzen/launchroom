<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Servers\MeshLinkData;
use App\Enums\Mesh\MeshPath;
use App\Models\Server;

/**
 * The private-network links between a team's servers for the topology on
 * the Servers page (docs/08 §3.9): every pair that one of the two reported
 * as a peer, with the best path either side saw (direct beats relayed beats
 * idle), plus the measured latency and transfer rates (docs/05 §2.1,
 * ServerLinkQuality). Servers of other teams never appear. Callers authorize.
 */
final readonly class ServerTopology
{
    public function __construct(
        private ServerLinkQuality $quality,
    ) {}

    /**
     * @param  iterable<Server>  $servers  the team's servers (revoked ones are skipped)
     * @return list<MeshLinkData>
     */
    public function links(iterable $servers): array
    {
        $byIp = [];
        $active = [];

        foreach ($servers as $server) {
            if ($server->revoked_at !== null) {
                continue;
            }

            $active[] = $server;

            if ($server->mesh_ip !== null) {
                $byIp[$server->mesh_ip] = $server;
            }
        }

        $rank = [MeshPath::Direct->value => 3, MeshPath::Relay->value => 2, MeshPath::Idle->value => 1];
        $links = [];

        foreach ($active as $server) {
            foreach ($server->mesh_status['peers'] ?? [] as $peer) {
                $other = $byIp[$peer['ipv4']] ?? null;

                if (! $other instanceof Server || $other->id === $server->id) {
                    continue;
                }

                $path = MeshPath::tryFrom($peer['connection']) ?? MeshPath::Idle;
                [$a, $b] = strcmp($server->public_id, $other->public_id) < 0 ? [$server, $other] : [$other, $server];
                $key = $a->public_id.'|'.$b->public_id;
                $seen = $links[$key] ?? null;

                if (! $seen instanceof MeshLinkData || $rank[$path->value] > $rank[$seen->path->value]) {
                    $links[$key] = new MeshLinkData($a->public_id, $b->public_id, $path, $path === MeshPath::Relay ? $peer['relay'] : null);
                }
            }
        }

        ksort($links);

        foreach ($this->quality->pairs($active) as $key => $measured) {
            if (isset($links[$key])) {
                $link = $links[$key];
                $links[$key] = new MeshLinkData($link->from, $link->to, $link->path, $link->relay, $measured['latencyMs'], $measured['speeds']);
            }
        }

        return array_values($links);
    }
}
