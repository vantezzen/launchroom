<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a canvas node's status line shows (docs/design-system.md §3): the UI
 * pairs each with an icon and a label, never colour alone.
 */
enum CanvasNodeState: string
{
    case Active = 'active';
    case Progress = 'progress';
    case Failed = 'failed';
    case Idle = 'idle';
    case Warning = 'warning';

    public function rank(): int
    {
        return match ($this) {
            self::Idle => 0,
            self::Active => 1,
            self::Progress => 2,
            self::Warning => 3,
            self::Failed => 4,
        };
    }
}
