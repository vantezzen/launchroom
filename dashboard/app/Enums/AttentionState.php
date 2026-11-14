<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The cue of a "Needs you" row (docs/08 §13): the UI pairs it with an icon
 * and words, never colour alone.
 */
enum AttentionState: string
{
    case Failed = 'failed';
    case Warning = 'warning';
    /** Waiting for the user, nothing wrong: staged changes, approvals. */
    case Staged = 'staged';
    case Idle = 'idle';

    public function rank(): int
    {
        return match ($this) {
            self::Failed => 3,
            self::Warning => 2,
            self::Staged => 1,
            self::Idle => 0,
        };
    }
}
