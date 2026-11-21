<?php

declare(strict_types=1);

namespace App\Listeners\ServiceStatus;

use App\Events\Broadcasts\ServiceStatusChanged;
use App\Events\ServerStatusChanged;
use App\Models\Placement;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * A server stopped reporting, went offline or came back: the services with
 * a live workload on it say so (D108, NodeStatus "Server unreachable").
 */
final class NudgeOnServerStatus
{
    public function handle(ServerStatusChanged $event): void
    {
        ServiceStatusChanged::forInstances(Placement::query()
            ->where('server_id', $event->serverId)
            ->whereHas('serviceInstance', static fn (Builder $query) => $query->whereColumn('service_instances.active_deployment_id', 'placements.deployment_id'))
            ->distinct()
            ->pluck('service_instance_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all());
    }
}
