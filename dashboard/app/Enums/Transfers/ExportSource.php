<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

/**
 * What an export reads: the data as it is now, or a stored backup.
 */
enum ExportSource: string
{
    case Live = 'live';
    case Snapshot = 'snapshot';
}
