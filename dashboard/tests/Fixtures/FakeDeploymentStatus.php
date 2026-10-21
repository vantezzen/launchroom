<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Test-only status enum exercising {@see HasTransitions}. Also analysed by
 * PHPStan so the trait is type-checked before a real status enum uses it.
 */
enum FakeDeploymentStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Queued = 'queued';
    case Building = 'building';
    case Active = 'active';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Building, self::Cancelled],
            self::Building => [self::Active, self::Failed, self::Cancelled],
            self::Active, self::Failed, self::Cancelled => [],
        };
    }
}
