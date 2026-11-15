<?php

declare(strict_types=1);

namespace App\Enums\Backups;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\StatusTone;

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

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Pending, self::Running => StatusTone::Progress,
            self::Succeeded => StatusTone::Active,
            self::Failed => StatusTone::Failed,
        };
    }
}
