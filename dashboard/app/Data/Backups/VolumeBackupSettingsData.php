<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\VolumeConsistency;
use Spatie\LaravelData\Data;

/**
 * Per-volume backup settings: how consistent its snapshots are.
 */
final class VolumeBackupSettingsData extends Data
{
    public function __construct(
        public VolumeConsistency $consistency,
    ) {}
}
