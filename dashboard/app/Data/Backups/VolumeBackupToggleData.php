<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * "Back up this volume" on or off.
 */
final class VolumeBackupToggleData extends Data
{
    public function __construct(
        public bool $enabled,
    ) {}
}
