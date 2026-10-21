<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * What a destination holds (Settings → Backups destination card): the size
 * of the newest snapshot of everything backed up there, by kind. restic
 * deduplicates, so this is the size of one full restore, not the bucket's.
 */
final class DestinationUsageData extends Data
{
    public function __construct(
        public string $destinationId,
        public ?CarbonInterface $lastBackupAt,
        public int $databaseBytes,
        public int $volumeBytes,
        public int $controlPlaneBytes,
    ) {}
}
