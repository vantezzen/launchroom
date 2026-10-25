<?php

declare(strict_types=1);

namespace App\Services\Volumes;

use App\Data\Specs\ServiceSpec;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\Moves\MoveItemKind;
use App\Enums\Moves\MoveItemStatus;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ImportRunStatus;
use App\Models\Backups\BackupRun;
use App\Models\Backups\RestoreRun;
use App\Models\Moves\ServerMoveItem;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Collection;

/**
 * Volume changes are staged like every other setting (docs/14 §9.12):
 * a new mount path or a removal applies with the next deploy, because
 * workloads mount what their deployment's revision recorded
 * (App\Services\NodeStates\NodeStateContent). These helpers answer what is
 * deployed and what still uses a volume.
 */
final class StagedVolumes
{
    /**
     * The mount paths the last deploy applied, by volume id.
     *
     * @return array<string, string>
     */
    public static function appliedPaths(ServiceInstance $instance): array
    {
        $instance->loadMissing(['service', 'environment.lastAppliedRevision']);
        $spec = $instance->environment->lastAppliedRevision?->spec->service($instance->service->public_id);
        $paths = [];

        foreach ($spec instanceof ServiceSpec ? $spec->volumes : [] as $id => $volume) {
            $paths[(string) $id] = $volume->mountPath;
        }

        return $paths;
    }

    /**
     * Removed volumes whose removal isn't deployed yet: they still serve the
     * running service and come back with a discard.
     *
     * @return Collection<int, Volume>
     */
    public static function pendingRemovals(ServiceInstance $instance): Collection
    {
        $applied = self::appliedPaths($instance);

        return Volume::query()
            ->where('service_instance_id', $instance->id)
            ->whereNotNull('removed_at')
            ->orderBy('id')
            ->get()
            ->filter(static fn (Volume $volume): bool => isset($applied[$volume->public_id]))
            ->values();
    }

    /**
     * Whether a deployment that still has workloads mounts the volume.
     */
    public static function mounted(Volume $volume): bool
    {
        $placements = Placement::query()
            ->where('service_instance_id', $volume->service_instance_id)
            ->with(['deployment.revision', 'serviceInstance.service'])
            ->get();

        foreach ($placements as $placement) {
            $spec = $placement->deployment->revision?->spec->service($placement->serviceInstance->service->public_id);

            if ($spec !== null && isset($spec->volumes[$volume->public_id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why the volume can't change right now, or null: a backup, restore,
     * import, export or server move is working with its files.
     */
    public static function busy(Volume $volume): ?string
    {
        $moving = array_values(array_map(static fn (MoveItemStatus $status): string => $status->value, array_filter(MoveItemStatus::cases(), static fn (MoveItemStatus $status): bool => $status->isActive())));

        return match (true) {
            BackupRun::query()->where('volume_id', $volume->id)->whereIn('status', [BackupRunStatus::Pending->value, BackupRunStatus::Running->value])->exists() => 'A backup of this volume is running. Try again once it has finished.',
            RestoreRun::query()->where('volume_id', $volume->id)->whereIn('status', [RestoreRunStatus::WaitingForSafetyBackup->value, RestoreRunStatus::WaitingForTarget->value, RestoreRunStatus::Restoring->value])->exists() => 'A restore into this volume is running. Try again once it has finished.',
            DataImportRun::query()->where('volume_id', $volume->id)->whereIn('status', [ImportRunStatus::WaitingForSafetyBackup->value, ImportRunStatus::Holding->value, ImportRunStatus::Importing->value])->exists() => 'An import into this volume is running. Try again once it has finished.',
            DataExportRun::query()->where('volume_id', $volume->id)->where('status', ExportRunStatus::Preparing->value)->exists() => 'A download of this volume is being prepared. Try again once it is ready.',
            ServerMoveItem::query()->where('service_instance_id', $volume->service_instance_id)->where('kind', MoveItemKind::Files->value)->whereIn('status', $moving)->exists() => 'This service is moving to another server. Try again once the move has finished.',
            default => null,
        };
    }
}
