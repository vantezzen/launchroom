<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * A row of the Backups page: something that holds data.
 */
enum BackupItemKind: string
{
    case ControlPlane = 'control_plane';
    case Database = 'database';
    case Volume = 'volume';
}
