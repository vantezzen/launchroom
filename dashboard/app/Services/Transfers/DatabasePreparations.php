<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Data\Services\DatabaseSource;
use App\Data\Transfers\DatabasePreparationData;
use App\Enums\DeploymentPurpose;
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
 * (`hold_traffic`, {@see DeploymentPurpose::PrepareImport}): it runs on its volume with the
 * credentials the apps will get, stops in `ready` and is never routed or
 * promoted. PrepareDatabaseForImport starts it,
 * EndDatabasePreparation stops it again (the volume stays), so the
 * first deploy finds the data in place.
 */
final class DatabasePreparations
{
    /** How long a preparation waits for an import before it is stopped. */
    public const int LEASE_SECONDS = 600;

    /**
     * The preparation of $instance that hasn't ended (queued, starting or ready), or null.
     */
    public static function current(ServiceInstance $instance): ?Deployment
    {
        return Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->unfinished()
            ->withPurpose(DeploymentPurpose::PrepareImport)
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
            ->withPurpose(DeploymentPurpose::PrepareImport)
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
            ->whereIn('phase', WorkloadPhase::serving())
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
            && ImportTarget::accepts($source->engine)
            && $instance->active_deployment_id === null
            && ! Deployment::query()
                ->where('service_instance_id', $instance->id)
                ->whereIn('status', DeploymentStatus::values([...DeploymentStatus::unfinished(), DeploymentStatus::Active]))
                ->withPurpose(DeploymentPurpose::Release)
                ->exists();
    }

    public static function importing(int $instanceId): bool
    {
        return DataImportRun::query()
            ->where('service_instance_id', $instanceId)
            ->whereIn('status', ImportRunStatus::nonTerminal())
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
