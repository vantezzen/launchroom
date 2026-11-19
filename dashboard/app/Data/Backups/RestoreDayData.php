<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * One day of restore points (the restore panel's timeline).
 */
final class RestoreDayData extends Data
{
    /**
     * @param  list<SnapshotData>  $points  newest first
     */
    public function __construct(
        /** Y-m-d (UTC). */
        public string $date,
        public array $points,
    ) {}
}
