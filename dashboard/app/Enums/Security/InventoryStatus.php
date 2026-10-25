<?php

declare(strict_types=1);

namespace App\Enums\Security;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of an image inventory (docs/22 §3.1): pending while its
 * `inventory` task runs, ready with its components, failed after every
 * attempt (and the two later retries) failed. A failed inventory goes back
 * to pending for a retry.
 */
enum InventoryStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Ready, self::Failed],
            self::Failed => [self::Pending],
            self::Ready => [],
        };
    }
}
