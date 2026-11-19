<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * The Backups page's headline.
 */
enum BackupsSummaryState: string
{
    case Unconfigured = 'unconfigured';
    case Running = 'running';
    case Attention = 'attention';
    case Waiting = 'waiting';
    case Ok = 'ok';
}
