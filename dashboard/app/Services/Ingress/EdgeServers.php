<?php

declare(strict_types=1);

namespace App\Services\Ingress;

use App\Models\Server;

/**
 * The approved servers with the edge role (docs/05 §4), install-wide or per
 * team: what re-renders when ingress settings change, and what the single
 * point of failure badge counts.
 */
final class EdgeServers
{
    /**
     * @return list<int>
     */
    public static function ids(?int $teamId = null): array
    {
        /** @var list<int> $ids */
        $ids = Server::query()
            ->when($teamId !== null, static fn ($query) => $query->where('team_id', $teamId))
            ->edges()
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * @return list<Server>
     */
    public function servers(): array
    {
        return array_values(Server::query()
            ->whereKey(self::ids())
            ->orderByDesc('is_control_plane')
            ->orderBy('name')
            ->get()
            ->all());
    }

    /**
     * The edges that share the install-wide certificate storage and DNS-01
     * credentials: the control plane and edges an instance owner admitted
     * ({@see Server::isSharedEdge()}).
     *
     * @return list<Server>
     */
    public function shared(): array
    {
        return array_values(array_filter($this->servers(), static fn (Server $server): bool => $server->isSharedEdge()));
    }
}
