<?php

declare(strict_types=1);

namespace App\Enums\Security;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * An investigation (docs/22 §7): running while its container inspections
 * run, then completed, partial (a replica couldn't be checked) or failed
 * (nothing could be checked).
 */
enum InvestigationStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Running => [self::Completed, self::Partial, self::Failed],
            default => [],
        };
    }
}
