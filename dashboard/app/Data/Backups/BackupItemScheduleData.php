<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * The schedule behind a Backups page row. Volumes share their service's
 * schedule: editing it changes every volume of that service.
 */
final class BackupItemScheduleData extends Data
{
    public function __construct(
        public string $id,
        public string $cron,
        public ?string $cronDescription,
        public string $retentionSummary,
        public bool $enabled,
        /** Volume rows: how many volumes of the service share it (this one included); 1 otherwise. */
        public int $sharedWithVolumes,
        /** PUT BackupScheduleInput here; null without permission. */
        public ?string $updateUrl,
        /** The full schedule for the edit sheet. */
        public ?BackupScheduleData $detail = null,
    ) {}
}
