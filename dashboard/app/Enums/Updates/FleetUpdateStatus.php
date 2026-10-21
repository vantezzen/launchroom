<?php

declare(strict_types=1);

namespace App\Enums\Updates;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a rolling launchroomd update across servers (docs/11 §4).
 */
enum FleetUpdateStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Running => [self::Succeeded, self::Failed],
            self::Succeeded, self::Failed => [],
        };
    }
}
