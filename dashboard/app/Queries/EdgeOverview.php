<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Ingress\EdgeOverviewData;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Ingress\EdgeServers;
use App\Support\DocsUrl;

/**
 * A team's (or the install's) edge servers at a glance: count, single point
 * of failure, degraded certificate storage (docs/02 §5, docs/05 §4–5).
 *
 * One server is the default and works well, so it is not a warning: the
 * single point of failure only counts once there are other servers that
 * could take public traffic too (maintainer feedback 2026-10-19). With one
 * server the UI shows a gentle, optional hint instead (`singleServer`).
 */
final readonly class EdgeOverview
{
    public function __construct(
        private EdgeServers $edges,
    ) {}

    public function for(?int $teamId): EdgeOverviewData
    {
        $ids = $this->edges->ids($teamId);
        $degraded = array_values(Server::query()
            ->whereKey($ids)
            ->where('ingress_status->storage->state', 'degraded')
            ->orderBy('name')
            ->get(['name'])
            ->map(static fn (Server $server): string => $server->name)
            ->all());

        $servers = Server::query()
            ->when($teamId !== null, static fn ($query) => $query->where('team_id', $teamId))
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
            ->count();

        return new EdgeOverviewData(
            edges: count($ids),
            singlePointOfFailure: count($ids) === 1 && $servers > 1,
            servers: $servers,
            singleServer: $servers === 1,
            degradedStorage: $degraded,
            guideUrl: DocsUrl::to('servers/high-availability-ingress'),
        );
    }
}
