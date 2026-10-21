<?php

declare(strict_types=1);

namespace App\Enums\Backups;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a restore (docs/14 §5.1). Restore-as-new waits for its new
 * service to run; restore-into waits for its safety snapshot first.
 */
enum RestoreRunStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case WaitingForSafetyBackup = 'waiting_for_safety_backup';
    case WaitingForTarget = 'waiting_for_target';
    case Restoring = 'restoring';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::WaitingForSafetyBackup => [self::WaitingForTarget, self::Restoring, self::Failed],
            self::WaitingForTarget => [self::Restoring, self::Failed],
            self::Restoring => [self::Succeeded, self::Failed],
            self::Succeeded, self::Failed => [],
        };
    }
}
