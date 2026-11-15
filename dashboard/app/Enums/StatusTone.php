<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The visual state behind a status word (docs/design-system.md §3.3). The UI
 * pairs each with an icon and the word, never colour alone.
 */
enum StatusTone: string
{
    /** Filled green dot: Live, Running, Online. */
    case Active = 'active';
    /** Amber spinner: Deploying, Building, Waiting. */
    case Progress = 'progress';
    /** Red ✕: Failed, Unreachable. */
    case Failed = 'failed';
    /** Amber ⚠: degraded, single point of failure, stale backup. */
    case Warning = 'warning';
    /** Hollow grey dot: Stopped, Superseded, Skipped, Not deployed. */
    case Idle = 'idle';
    /** Hollow signal dot: waiting for the user with nothing wrong (staged changes, approvals). */
    case Staged = 'staged';

    /** How urgent: the most urgent of several statuses wins a summary. */
    public function rank(): int
    {
        return match ($this) {
            self::Idle => 0,
            self::Active => 1,
            self::Staged => 2,
            self::Progress => 3,
            self::Warning => 4,
            self::Failed => 5,
        };
    }

    public function isProblem(): bool
    {
        return $this === self::Failed || $this === self::Warning;
    }
}
