<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\SnapshotListingStatus;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Everything the Backups tab of a database or a service with volumes shows
 * (features/backups/backups-tab.tsx).
 * Action URLs are included so the tab needs no knowledge of route names.
 */
final class ServiceBackupsData extends Data
{
    /**
     * @param  list<SnapshotData>  $snapshots
     * @param  list<BackupRunData>  $runs
     * @param  list<RestoreRunData>  $restores
     * @param  list<BackupVolumeData>  $volumes
     * @param  list<BackupTrendPointData>  $trend
     * @param  array{backup: string, refresh: string, restore: string, self: string, verify: string}  $urls
     */
    public function __construct(
        public string $serviceInstanceId,
        public string $serviceName,
        public string $confirmationText,
        /** database (dumps) or volume (snapshots); null: nothing to back up. */
        public ?BackupSubject $subject,
        public ?string $engine,
        public bool $supported,
        public bool $restoreSupported,
        public ?string $destinationName,
        public ?BackupScheduleData $schedule,
        public ServiceBackupStatusData $status,
        public array $snapshots,
        public ?SnapshotListingStatus $listingStatus,
        public ?CarbonInterface $listingFetchedAt,
        public ?string $listingError,
        public array $runs,
        public array $restores,
        public bool $canBackup,
        public bool $canRestoreAsNew,
        public bool $canRestoreInPlace,
        public array $urls,
        public array $volumes = [],
        /** Owners/admins: per-volume consistency. */
        public bool $canConfigure = false,
        /** A staged removal waiting for (or blocked on) its final backup (docs/14 §9.13). */
        public ?DeletionRequestData $deletion = null,
        /** Postgres: point-in-time recovery (docs/14 §10); null for other engines and volumes. */
        public ?PointInTimeData $pointInTime = null,
        /** The newest restore verification (docs/14 §10.4). */
        public ?BackupVerificationData $verification = null,
        public bool $canVerify = false,
        /** The last finished runs, oldest first: size, duration and success over time. */
        public array $trend = [],
    ) {}
}
