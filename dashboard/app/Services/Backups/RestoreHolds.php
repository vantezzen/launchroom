<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\WorkloadPhase;
use App\Models\Backups\RestoreRun;
use App\Models\Connection;
use App\Models\Placement;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Services\Moves\MoveHolds;
use App\Services\Transfers\ImportHolds;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Services held for a restore (docs/14 §5.1, §9.12): while a restore is
 * `restoring`, the held instances' workloads are left out of their servers'
 * NodeStates (App\Services\NodeStates\NodeStateContent), so launchroomd
 * stops them; the restore's end re-renders those servers and they start again.
 *
 * - Volume restores hold the service whose volume is replaced.
 * - In-place database restores hold the database's dependents: the current
 *   instances in the same environment of every service connected to it,
 *   and of those services' workers and crons.
 *   The database itself keeps running (the restore runs inside it).
 * - Point-in-time restores (subject base_backup) hold the database itself
 *   (its volume is replaced) and, in place, its dependents (docs/14 §10.3).
 * - Imports hold like restores ({@see ImportHolds}, docs/14 §12.5).
 * - Server moves hold the services that write to a database or volume
 *   while it is copied ({@see MoveHolds}, docs/14 §13.2).
 */
final class RestoreHolds
{
    /** A workload its server's reports leave out for this long doesn't run there. */
    public const int SILENT_SECONDS = 120;

    /**
     * @return array<int, true> held service instance ids
     */
    public static function instanceIds(): array
    {
        $held = [];

        $runs = RestoreRun::query()
            ->where('status', RestoreRunStatus::Restoring->value)
            ->whereNotNull('target_service_instance_id')
            ->where(static fn (Builder $query) => $query
                ->whereIn('subject', [BackupSubject::Volume->value, BackupSubject::BaseBackup->value])
                ->orWhere('mode', RestoreMode::InPlace->value))
            ->get(['id', 'subject', 'mode', 'target_service_instance_id']);

        foreach ($runs as $run) {
            foreach (self::heldBy($run) as $id) {
                $held[$id] = true;
            }
        }

        // Imports and server moves hold services the same way (docs/14 §12.5, §13.2).
        return $held + ImportHolds::instanceIds() + MoveHolds::instanceIds();
    }

    /**
     * The instances a restore holds while it runs (whatever its status).
     *
     * @return list<int>
     */
    public static function heldBy(RestoreRun $run): array
    {
        if ($run->target_service_instance_id === null) {
            return [];
        }

        if ($run->subject === BackupSubject::Volume) {
            return [$run->target_service_instance_id];
        }

        if ($run->subject === BackupSubject::BaseBackup) {
            // A point-in-time restore replaces the data volume: the database
            // itself stops, and in place so do its dependents.
            $database = ServiceInstance::query()->whereKey($run->target_service_instance_id)->first(['id', 'service_id', 'environment_id']);

            return [$run->target_service_instance_id, ...($run->mode === RestoreMode::InPlace && $database instanceof ServiceInstance ? self::dependentIds($database) : [])];
        }

        if ($run->mode !== RestoreMode::InPlace) {
            return [];
        }

        $database = ServiceInstance::query()->whereKey($run->target_service_instance_id)->first(['id', 'service_id', 'environment_id']);

        return $database instanceof ServiceInstance ? self::dependentIds($database) : [];
    }

    /**
     * Current instances in the database's environment of the services that
     * are connected to it (canvas edges into the database), and of the
     * workers and crons of those services: they run the same code with the
     * same data, whether or not they have an edge of their own.
     *
     * @return list<int>
     */
    public static function dependentIds(ServiceInstance $database): array
    {
        $consumers = Connection::query()->where('to_service_id', $database->service_id)->select('from_service_id');
        $processes = Service::query()->whereIn('parent_service_id', $consumers)->select('id');

        /** @var list<int> $ids */
        $ids = ServiceInstance::query()
            ->current()
            ->where('environment_id', $database->environment_id)
            ->where(static fn (Builder $query) => $query->whereIn('service_id', $consumers)->orWhereIn('service_id', $processes))
            ->whereKeyNot($database->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        return $ids;
    }

    /**
     * Servers that run (or ran) workloads of the given instances: they
     * re-render when a hold starts or ends.
     *
     * @param  list<int>  $instanceIds
     * @return list<int>
     */
    public static function serverIds(array $instanceIds): array
    {
        if ($instanceIds === []) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = Placement::query()
            ->whereIn('service_instance_id', $instanceIds)
            ->distinct()
            ->pluck('server_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->sort()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * Whether no workload of the given instances is still up (all stopped,
     * removed, or never started) — the held services have let go. A service
     * whose deployment failed before it ran has no workload up and counts as
     * stopped.
     *
     * @param  list<int>  $instanceIds
     */
    public static function released(array $instanceIds): bool
    {
        return self::running($instanceIds) === [];
    }

    /**
     * The given instances that still have a workload up on some server
     * ({@see runningPlacements}).
     *
     * @param  list<int>  $instanceIds
     * @return list<int>
     */
    public static function running(array $instanceIds): array
    {
        /** @var list<int> $ids */
        $ids = self::runningPlacements($instanceIds)
            ->pluck('service_instance_id')
            ->unique()
            ->sort()
            ->values()
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        return $ids;
    }

    /**
     * The workloads of the given instances that launchroomd still reports
     * up. Judged on what it reports, not on a stale status: launchroomd
     * reports every workload it runs (and removed ones for a while) in every
     * report, so a workload its server keeps reporting without it for
     * `SILENT_SECONDS` runs no container there, whatever it was last seen as
     * (a container removed without a report, a worker never recreated). A
     * workload that never got a container (pulling, pending, failed) isn't
     * up either.
     *
     * @param  list<int>  $instanceIds
     * @return Collection<int, Placement>
     */
    public static function runningPlacements(array $instanceIds): Collection
    {
        if ($instanceIds === []) {
            return new Collection;
        }

        $up = [WorkloadPhase::Starting->value, WorkloadPhase::Running->value, WorkloadPhase::Healthy->value, WorkloadPhase::Unhealthy->value, WorkloadPhase::Draining->value];

        return Placement::query()
            ->whereIn('service_instance_id', $instanceIds)
            ->whereIn('phase', $up)
            ->with('server')
            ->orderBy('server_id')
            ->orderBy('id')
            ->get()
            ->filter(static fn (Placement $placement): bool => self::stillReported($placement))
            ->values();
    }

    /**
     * Whether the latest reports of its server still mention $placement
     * (each report that does updates it). A server that stopped reporting
     * altogether can't tell: its workloads count as up.
     */
    private static function stillReported(Placement $placement): bool
    {
        $reportedAt = $placement->server->last_report_at;

        return ! $reportedAt instanceof CarbonInterface
            || ! $placement->updated_at instanceof CarbonInterface
            || $placement->updated_at->gte($reportedAt->copy()->subSeconds(self::SILENT_SECONDS));
    }
}
