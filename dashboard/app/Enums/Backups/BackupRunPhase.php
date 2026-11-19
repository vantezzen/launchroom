<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * Where a backup in flight is (the Backups page's running row). Read from
 * launchroomd's progress lines (`::progress:: {json}`, op `backup`) and,
 * for older servers, its human-readable log lines.
 */
enum BackupRunPhase: string
{
    /** launchroom created the run; the server has not started it. */
    case Queued = 'queued';
    /** The server holds it behind another backup, restore, import or export. */
    case Waiting = 'waiting';
    /** Opening (or creating) the repository. */
    case Preparing = 'preparing';
    /** Reading the dump or volume and uploading it. */
    case Streaming = 'streaming';
    /** Everything read; storing the last data and the snapshot. */
    case Finishing = 'finishing';
    case Stored = 'stored';
}
