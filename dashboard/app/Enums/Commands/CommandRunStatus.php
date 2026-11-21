<?php

declare(strict_types=1);

namespace App\Enums\Commands;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\StatusTone;

/**
 * Lifecycle of a one-off command (D105, docs/03 §7.5): queued until its
 * server leases the task, running while the container runs, then how it
 * ended. `failed` is a non-zero exit; `error` means the command never ran to
 * its end for a reason outside it (no server, image pull, launchroomd
 * restarted).
 */
enum CommandRunStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';
    case Error = 'error';

    public function allowedTransitions(): array
    {
        $ended = [self::Succeeded, self::Failed, self::TimedOut, self::Cancelled, self::Error];

        return match ($this) {
            self::Queued => [self::Running, ...$ended],
            self::Running => $ended,
            self::Succeeded, self::Failed, self::TimedOut, self::Cancelled, self::Error => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::TimedOut => 'Timed out',
            self::Cancelled => 'Cancelled',
            self::Error => 'Couldn’t run',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Queued, self::Running => StatusTone::Progress,
            self::Succeeded => StatusTone::Active,
            self::Failed, self::TimedOut, self::Error => StatusTone::Failed,
            self::Cancelled => StatusTone::Idle,
        };
    }
}
