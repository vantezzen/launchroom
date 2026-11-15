<?php

declare(strict_types=1);

namespace App\Services\Holds;

use App\Data\Holds\Hold;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\HoldKind;
use App\Models\Backups\RestoreRun;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Services\Volumes\MountedVolumes;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Services held for a restore (docs/14 §5.1, §9.12, §10.3):
 *
 * - Volume restores hold the service whose volume is replaced, and the
 *   services that share its volumes (D73).
 * - In-place database restores hold the database's dependents
 *   ({@see Holds::dependentsOf}); the database itself keeps running (the
 *   restore runs inside it).
 * - Point-in-time restores (subject base_backup) hold the database itself
 *   (its volume is replaced) and, in place, its dependents.
 */
final class RestoreHolds implements HoldSource
{
    public static function active(): array
    {
        $runs = RestoreRun::query()
            ->where('status', RestoreRunStatus::Restoring->value)
            ->whereNotNull('target_service_instance_id')
            ->where(static fn (Builder $query) => $query
                ->whereIn('subject', [BackupSubject::Volume->value, BackupSubject::BaseBackup->value])
                ->orWhere('mode', RestoreMode::InPlace->value))
            ->with('target.service')
            ->orderBy('id')
            ->get();

        $holds = [];

        foreach ($runs as $run) {
            $holds[] = new Hold(HoldKind::Restore, self::heldBy($run), ($run->target?->service->name ?? 'a service').' is being restored', 'the restore');
        }

        return $holds;
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
            $volumes = Volume::query()->where('service_instance_id', $run->target_service_instance_id)->pluck('id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->values()->all();

            return [$run->target_service_instance_id, ...MountedVolumes::borrowersOf($volumes)];
        }

        $database = ServiceInstance::query()->whereKey($run->target_service_instance_id)->first(['id', 'service_id', 'environment_id']);
        $dependents = $run->mode === RestoreMode::InPlace && $database instanceof ServiceInstance ? Holds::dependentsOf($database) : [];

        return $run->subject === BackupSubject::BaseBackup ? [$run->target_service_instance_id, ...$dependents] : $dependents;
    }
}
