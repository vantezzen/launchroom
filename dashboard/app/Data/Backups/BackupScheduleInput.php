<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Data\Attributes\PublicIdOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Edit a backup schedule: cron (UTC), retention, on/off, destination.
 * Fields left out keep their value (`{enabled: true}` turns a schedule
 * back on from the Backups page's "Not backed up" list).
 */
final class BackupScheduleInput extends Data
{
    public function __construct(
        #[Max(64)]
        public ?string $cron = null,
        public ?RetentionData $retention = null,
        public bool $enabled = true,
        #[PublicIdOf('bkd')]
        public ?string $destination = null,
    ) {}
}
