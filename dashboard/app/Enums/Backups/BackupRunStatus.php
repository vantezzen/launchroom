<?php

declare(strict_types=1);

namespace App\Enums\Backups;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of one backup run (docs/14 §3, docs/07 `backup_runs`).
 */
enum BackupRunStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Running, self::Succeeded, self::Failed],
            self::Running => [self::Succeeded, self::Failed],
            self::Succeeded, self::Failed => [],
        };
    }
}
