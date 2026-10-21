<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a "Replace server" run (docs/14 §5.3): running while the
 * restores and re-placements it started are under way.
 */
enum ServerReplacementStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Running => [self::Completed, self::Failed],
            self::Completed, self::Failed => [],
        };
    }
}
