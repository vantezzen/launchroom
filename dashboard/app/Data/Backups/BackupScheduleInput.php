<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Data\Attributes\PublicIdOf;
use Spatie\LaravelData\Attributes\Validation\Max;
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
        #[PublicIdOf('bkd')]
        public ?string $destination = null,
    ) {}
}
