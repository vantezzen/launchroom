<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * State of the cached `restic snapshots` listing of one database.
 */
enum SnapshotListingStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
