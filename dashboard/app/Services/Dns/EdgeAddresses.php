<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;

/**
 * Public addresses of the servers with the edge role: what DNS records of
 * custom domains and the platform domain must point at (docs/05 §5).
 */
final class EdgeAddresses
{
    /**
     * @return list<array{string, string}> [record type, address] in server order
     */
    public function records(?int $teamId): array
    {
        $edges = Server::query()
            ->when($teamId !== null, static fn ($query) => $query->where('team_id', $teamId))
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value])
            ->whereJsonContains('roles', ServerRole::Edge->value)
            ->orderByDesc('is_control_plane')
            ->orderBy('id')
            ->get(['public_ipv4', 'public_ipv6']);

        $records = [];

        foreach ($edges as $edge) {
            if ($edge->public_ipv4 !== null) {
                $records[] = ['A', $edge->public_ipv4];
            }

            if ($edge->public_ipv6 !== null) {
                $records[] = ['AAAA', $edge->public_ipv6];
            }
        }

        return array_values(array_unique($records, SORT_REGULAR));
    }

    /**
     * @return list<string>
     */
    public function addresses(?int $teamId): array
    {
        return array_values(array_unique(array_map(static fn (array $record): string => $record[1], $this->records($teamId))));
    }
}
