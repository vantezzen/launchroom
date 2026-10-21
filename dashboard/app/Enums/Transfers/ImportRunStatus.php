<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of an import (docs/14 §12.5): a safety snapshot first when
 * replacing, then the services that use the data are held, then the task
 * imports. Before the task starts a run can still be cancelled.
 */
enum ImportRunStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case WaitingForSafetyBackup = 'waiting_for_safety_backup';
    case Holding = 'holding';
    case Importing = 'importing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::WaitingForSafetyBackup => [self::Holding, self::Failed, self::Cancelled],
            self::Holding => [self::Importing, self::Failed, self::Cancelled],
            self::Importing => [self::Succeeded, self::Failed],
            self::Succeeded, self::Failed, self::Cancelled => [],
        };
    }

    /** Whether the run's services are held (their workloads leave the desired state). */
    public function holds(): bool
    {
        return $this === self::Holding || $this === self::Importing;
    }
}
