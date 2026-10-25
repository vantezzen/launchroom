<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Actions\Transfers\EndDatabasePreparation;
use App\Actions\Transfers\PrepareDatabaseForImport;
use App\Data\Services\DatabaseSource;
use App\Data\Transfers\DatabasePreparationData;
use App\Enums\DeploymentStatus;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataImportRun;
use App\Services\Backups\DatabaseWorkloads;

/**
 * Databases started alone for an import before their first deploy (docs/14
 * §12.9). A preparation is a staged deployment of the database only
 * (`hold_traffic`, `plan.prepareImport`): it runs on its volume with the
 * credentials the apps will get, stops in `ready` and is never routed or
 * promoted. {@see PrepareDatabaseForImport} starts it,
 * {@see EndDatabasePreparation} stops it again (the volume stays), so the
 * first deploy finds the data in place.
 */
final class DatabasePreparations
{
    public const string KEY = 'prepareImport';

    /** How long a preparation waits for an import before it is stopped. */
    public const int LEASE_SECONDS = 600;

    public static function is(Deployment $deployment): bool
    {
        return is_array($deployment->plan[self::KEY] ?? null);
    }

    /**
     * The preparation of $instance that hasn't ended (queued, starting or ready), or null.
     */
    public static function current(ServiceInstance $instance): ?Deployment
    {
        return Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight()]))
            ->whereNotNull('plan->'.self::KEY)
            ->latest('id')
            ->first();
    }

    /**
     * The newest preparation of $instance, also an ended one (its failure is shown).
     */
    public static function latest(ServiceInstance $instance): ?Deployment
    {
        return Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->whereNotNull('plan->'.self::KEY)
            ->latest('id')
            ->first();
    }

    /**
     * The running database of a ready preparation (with its server), or null.
     */
    public static function placement(ServiceInstance $instance): ?Placement
    {
        $deployment = self::current($instance);

        if (! $deployment instanceof Deployment || $deployment->status !== DeploymentStatus::Ready) {
            return null;
        }

        return Placement::query()
            ->where('deployment_id', $deployment->id)
            ->where('role', WorkloadRole::Serving->value)
            ->whereIn('phase', [WorkloadPhase::Healthy->value, WorkloadPhase::Running->value])
            ->with('server')
            ->orderBy('slot')
            ->first();
    }

    /**
     * Where an import or export of a database runs: its serving placement,
     * else the one of a ready preparation.
     */
    public static function importPlacement(ServiceInstance $instance): ?Placement
    {
        return DatabaseWorkloads::servingPlacement($instance) ?? self::placement($instance);
    }

    /**
     * A database that can be prepared: importable, never deployed, nothing
     * else of it queued or running.
     */
    public static function preparable(ServiceInstance $instance): bool
    {
        $source = DatabaseWorkloads::source($instance);

        return $source instanceof DatabaseSource
            && in_array($source->engine, ImportTarget::IMPORTABLE, true)
            && $instance->active_deployment_id === null
            && ! Deployment::query()
                ->where('service_instance_id', $instance->id)
                ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight(), DeploymentStatus::Active]))
                ->whereNull('plan->'.self::KEY)
                ->exists();
    }

    /** An import of $instanceId is waiting or running. */
    public static function importing(int $instanceId): bool
    {
        return DataImportRun::query()
            ->where('service_instance_id', $instanceId)
            ->whereIn('status', [ImportRunStatus::WaitingForSafetyBackup->value, ImportRunStatus::Holding->value, ImportRunStatus::Importing->value])
            ->exists();
    }

    /**
     * What the Move data section shows for a database that was never
     * deployed, or null for one that was (it imports as usual).
     */
    public static function view(ServiceInstance $instance): ?DatabasePreparationData
    {
        $current = self::current($instance);

        if (! $current instanceof Deployment && ! self::preparable($instance)) {
            return null;
        }

        if ($current instanceof Deployment) {
            $placement = self::placement($instance);

            return new DatabasePreparationData(
                state: $placement instanceof Placement ? 'ready' : 'starting',
                serverName: $placement?->server->name ?? Placement::query()->where('deployment_id', $current->id)->with('server:id,name')->first()?->server->name,
                message: null,
            );
        }

        $latest = self::latest($instance);
        $failed = $latest instanceof Deployment && $latest->status === DeploymentStatus::Failed && $latest->created_at?->gt(now()->subHour()) === true;

        return new DatabasePreparationData(
            state: $failed ? 'failed' : 'needed',
            serverName: null,
            message: $failed ? $latest->failure_message : null,
        );
    }
}
