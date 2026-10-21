<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Shares a case value with {@see FakeDeploymentStatus} to prove transitions
 * never cross enum types.
 */
enum OtherStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Queued = 'queued';

    public function allowedTransitions(): array
    {
        return [self::Queued];
    }
}
