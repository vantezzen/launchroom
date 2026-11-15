<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * Point-in-time recovery on/off and how many days back (docs/14 §10.1); the
 * range is UpdatePointInTimeRecovery's rule.
 */
final class PointInTimeSettingsData extends Data
{
    public function __construct(
        public bool $enabled,
        public int $keepDays,
    ) {}
}
