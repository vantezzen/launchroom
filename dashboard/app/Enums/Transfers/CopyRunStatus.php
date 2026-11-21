<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a copy between environments (D110, docs/14 §14.2): the
 * source's data is exported, then imported into the target (safety
 * snapshot, apps held, data replaced, apps started again). Before the
 * import task runs a copy can still be cancelled.
 */
enum CopyRunStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Exporting = 'exporting';
    case Importing = 'importing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Exporting => [self::Importing, self::Failed, self::Cancelled],
            self::Importing => [self::Succeeded, self::Failed, self::Cancelled],
            self::Succeeded, self::Failed, self::Cancelled => [],
        };
    }
}
