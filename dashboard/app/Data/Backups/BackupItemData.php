<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\BackupItemKind;
use App\Enums\Backups\BackupItemState;
use App\Enums\EnvironmentKind;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One row of the Backups page: a database, a volume, or launchroom itself.
 */
final class BackupItemData extends Data
{
    /**
     * @param  list<BackupDayData>  $days  oldest first
     */
    public function __construct(
        /** `launchroom`, `database:<sin_…>` or `volume:<vol_…>`. */
        public string $id,
        public BackupItemKind $kind,
        /** Database: service name; volume: mount path; launchroom itself: "launchroom". */
        public string $name,
        public ?string $serviceName,
        public ?string $mountPath,
        public ?string $engine,
        public ?string $engineVersion,
        public ?string $projectName,
        public ?string $environmentName,
        public ?EnvironmentKind $environmentKind,
        public ?string $serviceInstanceId,
        public ?string $volumeId,
        public BackupItemState $state,
        public ?CarbonInterface $lastSucceededAt,
        /** Newest successful copy of this item (a volume's own, never another volume's). */
        public ?int $lastSizeBytes,
        /** Volumes: current size on the server (its disk breakdown). */
        public ?int $liveSizeBytes,
        public ?CarbonInterface $nextRunAt,
        /** Failed only; secret-free. */
        public ?string $lastError,
        public array $days,
        public ?BackupItemScheduleData $schedule,
        public ?BackupRunProgressData $run,
        public BackupActionData $backUp,
        public ?BackupVolumeSwitchData $volumeBackup,
        /** The service's Backups tab, where restoring happens. */
        public ?string $restoreHref,
        public ?string $historyHref,
        /** Its schedule's storage was removed: nothing runs until storage is added again. */
        public bool $storageRemoved = false,
    ) {}
}
