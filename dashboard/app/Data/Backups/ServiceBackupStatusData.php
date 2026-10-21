<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\BackupHealth;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Backup health of one database, for canvas nodes ("last backup N h ago",
 * docs/14 §3 "Visibility") and the Backups tab.
 *
 * status: `ok` (recent success), `stale` (last success older than
 * launchroom.backups.stale_after_hours), `failing` (the latest finished run
 * failed), `running`, `none` (scheduled, no run yet), `unconfigured` (no
 * destination: backups are off).
 */
final class ServiceBackupStatusData extends Data
{
    public function __construct(
        public BackupHealth $status,
        public ?CarbonInterface $lastSuccessfulAt,
        public ?CarbonInterface $nextRunAt,
        public ?string $lastError,
    ) {}
}
