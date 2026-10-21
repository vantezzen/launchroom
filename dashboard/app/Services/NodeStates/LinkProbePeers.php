<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Enums\DaemonFeature;
use App\Models\Server;
use App\Queries\MeshServers;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The peers a server measures and accepts link probes from
 * (`system.linkProbe`, docs/05 §2.1): the other mesh servers of its team plus
 * the control plane's server (the control plane: every mesh server), each
 * with its mesh address and launchroomd signing key. Both sides must have a
 * mesh address, `measure_links` on and a launchroomd that announced the
 * `linkProbe` feature, so the relation is symmetric: A lists B exactly when
 * B lists A, and each answers only the other's probes.
 */
final readonly class LinkProbePeers
{
    /**
     * @return list<array{serverId: string, ipv4: string, signingKey: string}>|null null = probing off for this server
     */
    public function for(Server $server, bool $meshEnabled): ?array
    {
        if (! $meshEnabled || ! $this->eligible($server)) {
            return null;
        }

        $peers = MeshServers::query()
            ->whereKeyNot($server->id)
            ->whereNotNull('mesh_ip')
            ->where('measure_links', true)
            ->unless($server->is_control_plane, fn ($query) => $query->where(
                fn (Builder $q) => $q->where('team_id', $server->team_id)->orWhere('is_control_plane', true),
            ))
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($peers as $peer) {
            if (! $this->eligible($peer) || $peer->mesh_ip === null || ! str_starts_with($peer->signing_public_key, 'ed25519:')) {
                continue;
            }

            $out[] = ['serverId' => $peer->public_id, 'ipv4' => $peer->mesh_ip, 'signingKey' => $peer->signing_public_key];
        }

        return array_slice($out, 0, 256);
    }

    private function eligible(Server $server): bool
    {
        return $server->measure_links
            && $server->mesh_ip !== null
            && $server->revoked_at === null
            && $server->supportsDaemonFeature(DaemonFeature::LinkProbe);
    }
}
