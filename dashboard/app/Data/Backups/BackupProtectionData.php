<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * The last 30 days of one schedule (Settings → Backups, "What's protected").
 */
final class BackupProtectionData extends Data
{
    /**
     * @param  list<BackupDayData>  $days  oldest first, today last
     */
    public function __construct(
        public string $scheduleId,
        public array $days,
        public ?CarbonInterface $lastSucceededAt,
        /** Size of the newest successful snapshot. */
        public ?int $lastSizeBytes,
    ) {}
}
