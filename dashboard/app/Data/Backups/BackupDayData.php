<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * One day of a backup run strip (Settings → Backups, "What's protected").
 */
final class BackupDayData extends Data
{
    public function __construct(
        /** Y-m-d (UTC). */
        public string $date,
        /** ok: at least one run succeeded; failed: runs, none succeeded; none: no run. */
        public string $status,
    ) {}
}
