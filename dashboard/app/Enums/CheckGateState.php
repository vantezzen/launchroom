<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Where a push deployment stands with its commit's CI checks (D111,
 * docs/06 §2.5). Only `waiting` holds the deployment in the queue; every
 * other state is final: `passed`, `none` (no check appeared within the
 * grace period), `unsupported` (the git server has no checks) and
 * `skipped` (Deploy now) let it start, `failed` and `timed_out` cancel it.
 */
enum CheckGateState: string implements HasTransitionTable
{
    use HasTransitions;

    case Waiting = 'waiting';
    case Passed = 'passed';
    case None = 'none';
    case Unsupported = 'unsupported';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case TimedOut = 'timed_out';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Waiting => [self::Passed, self::None, self::Skipped, self::Failed, self::TimedOut],
            default => [],
        };
    }

    /** Whether the deployment may start. */
    public function letsDeploy(): bool
    {
        return in_array($this, [self::Passed, self::None, self::Unsupported, self::Skipped], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting for checks',
            self::Passed => 'Checks passed',
            self::None => 'No checks reported',
            self::Unsupported => 'No checks on this git server',
            self::Skipped => 'Deployed without waiting',
            self::Failed => 'Checks failed',
            self::TimedOut => 'Checks took too long',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Waiting => StatusTone::Progress,
            self::Passed => StatusTone::Active,
            self::None, self::Unsupported, self::Skipped => StatusTone::Idle,
            self::Failed => StatusTone::Failed,
            self::TimedOut => StatusTone::Warning,
        };
    }
}
