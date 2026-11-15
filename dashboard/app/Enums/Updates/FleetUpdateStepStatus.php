<?php

declare(strict_types=1);

namespace App\Enums\Updates;

use App\Enums\Attributes\SharedFacts;
use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * One server's step in a rolling update. Steps run strictly one at a time;
 * after a failure the remaining steps are skipped.
 */
#[SharedFacts('isFailed')]
enum FleetUpdateStepStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    case Updating = 'updating';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case Skipped = 'skipped';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Updating, self::Skipped],
            self::Updating => [self::Succeeded, self::Failed, self::RolledBack],
            self::Succeeded, self::Failed, self::RolledBack, self::Skipped => [],
        };
    }

    /** Finished without the new version running: failed, or restored to the previous one. */
    public function isFailed(): bool
    {
        return $this === self::Failed || $this === self::RolledBack;
    }
}
