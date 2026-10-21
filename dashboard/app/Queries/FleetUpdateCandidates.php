<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Support\Semver;

/**
 * Servers a rolling launchroomd update to a version touches, in rollout
 * order (docs/11 §4): servers without the edge role first, then edges
 * (they carry the traffic), each group by enrollment. Left out: the
 * control-plane server (updated with the control plane), revoked or
 * unapproved servers, servers already at the version and servers that run
 * a newer one (no downgrades).
 */
final class FleetUpdateCandidates
{
    /**
     * @return list<Server>
     */
    public function forVersion(string $target): array
    {
        $servers = Server::query()
            ->where('is_control_plane', false)
            ->whereNotIn('status', [ServerStatus::Revoked->value, ServerStatus::PendingApproval->value])
            ->orderBy('id')
            ->get()
            ->filter(static fn (Server $server): bool => $server->daemon_version === null
                || Semver::compare($server->daemon_version, $target) === -1);

        $edges = $servers->filter(static fn (Server $server): bool => $server->roles->contains(ServerRole::Edge));
        $others = $servers->reject(static fn (Server $server): bool => $server->roles->contains(ServerRole::Edge));

        return [...$others->values()->all(), ...$edges->values()->all()];
    }
}
