<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * Where the dashboard learned about a snapshot.
 */
enum SnapshotSource: string
{
    /** A backup run recorded by this dashboard. */
    case Run = 'run';
    /** Only in the repository listing (e.g. older than this dashboard). */
    case Repository = 'repository';
}
