<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * How a volume is read during a backup (docs/14 §2; protocol
 * VolumeConsistency): `live` while the service runs (uploads-style data),
 * or `pause` (the service's containers are frozen while the volume is read;
 * for SQLite files and anything else written in place).
 */
enum VolumeConsistency: string
{
    case Live = 'live';
    case Pause = 'pause';

    public function label(): string
    {
        return match ($this) {
            self::Live => 'Live (service keeps running)',
            self::Pause => 'Pause the service while reading',
        };
    }
}
