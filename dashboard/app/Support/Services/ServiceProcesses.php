<?php

declare(strict_types=1);

namespace App\Support\Services;

use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use Illuminate\Database\Eloquent\Collection;

/**
 * The processes of an app (its workers and crons: they run its image) in
 * the same environment, and the team's edge servers, for the service
 * lifecycle actions (D68).
 */
final class ServiceProcesses
{
    /**
     * @return Collection<int, ServiceInstance>
     */
    public static function of(ServiceInstance $instance): Collection
    {
        return ServiceInstance::query()
            ->current()
            ->where('environment_id', $instance->environment_id)
            ->whereIn('service_id', Service::query()->select('id')->where('parent_service_id', $instance->service_id))
            ->with(['service', 'activeDeployment'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<int>
     */
    public static function edges(int $teamId): array
    {
        /** @var list<int> $ids */
        $ids = Server::query()
            ->where('team_id', $teamId)
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
            ->whereJsonContains('roles', ServerRole::Edge->value)
            ->pluck('id')
            ->all();

        return $ids;
    }
}
