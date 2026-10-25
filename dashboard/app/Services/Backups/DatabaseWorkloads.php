<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Data\Services\DatabaseSource;
use App\Enums\DaemonFeature;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;

/**
 * Where a database service instance runs right now: the serving, healthy
 * placement of its active deployment. Backup and restore tasks go to that
 * server (docs/14 §2: the dump runs inside the database container).
 */
final class DatabaseWorkloads
{
    /**
     * Daemon engine names (protocol DatabaseEngine) by dashboard engine.
     */
    public static function daemonEngine(DatabaseSource $source): string
    {
        return $source->engine->value;
    }

    /**
     * Why $server's launchroomd can't run backup, restore or verification
     * tasks of this engine (it predates the engine, D47), or null.
     */
    public static function unsupportedBy(DatabaseSource $source, Server $server, string $doing = 'back up'): ?string
    {
        $feature = $source->engine->daemonFeature();

        return ! $feature instanceof DaemonFeature || $server->supportsDaemonFeature($feature)
            ? null
            : "Update {$server->name} in Settings → Updates to {$doing} {$source->engine->label()}.";
    }

    public static function source(ServiceInstance $instance): ?DatabaseSource
    {
        $source = $instance->service->source;

        return $source instanceof DatabaseSource ? $source : null;
    }

    public static function servingPlacement(ServiceInstance $instance): ?Placement
    {
        if ($instance->active_deployment_id === null) {
            return null;
        }

        return Placement::query()
            ->where('service_instance_id', $instance->id)
            ->where('deployment_id', $instance->active_deployment_id)
            ->where('role', WorkloadRole::Serving->value)
            ->whereIn('phase', [WorkloadPhase::Healthy->value, WorkloadPhase::Running->value])
            ->with('server')
            ->orderBy('slot')
            ->first();
    }
}
