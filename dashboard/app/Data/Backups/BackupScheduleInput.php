<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Edit a backup schedule: cron (UTC), retention, on/off, destination.
 */
final class BackupScheduleInput extends Data
{
    public function __construct(
        #[Max(64)]
        public string $cron,
        public RetentionData $retention,
        public bool $enabled = true,
        #[Regex('/^bkd_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public ?string $destination = null,
    ) {}
}
