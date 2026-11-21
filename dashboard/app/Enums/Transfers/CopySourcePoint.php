<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

/**
 * What a copy reads from the source (docs/14 §14.1): its newest backup, a
 * backup picked from the list, or a dump of the live data now.
 */
enum CopySourcePoint: string
{
    case LatestBackup = 'latest_backup';
    case Backup = 'backup';
    case Live = 'live';

    public function readsBackup(): bool
    {
        return $this !== self::Live;
    }
}
