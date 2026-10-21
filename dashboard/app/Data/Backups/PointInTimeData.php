<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\WalArchiveState;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Point-in-time recovery of a Postgres database on its Backups tab (docs/14
 * §10). `windowStart`/`windowEnd`: the range a time can be picked from
 * (null until the first base backup finished).
 */
final class PointInTimeData extends Data
{
    public function __construct(
        /** Postgres 15+ outside previews. */
        public bool $supported,
        public bool $enabled,
        public int $keepDays,
        public ?WalArchiveState $state,
        public ?CarbonInterface $lastArchivedAt,
        public int $pendingSegments,
        public ?string $message,
        public ?CarbonInterface $windowStart,
        public ?CarbonInterface $windowEnd,
        public ?CarbonInterface $nextBaseBackupAt,
        /** Owners/admins: turn it on/off, change how far back. */
        public bool $canConfigure,
        /** False when the database's server runs a launchroomd without point-in-time support. */
        public bool $serverReady,
        public string $updateUrl,
    ) {}
}
