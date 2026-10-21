<?php

declare(strict_types=1);

namespace App\Enums\Alerts;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * A delivery is pending while its job tries (with retries), then sent or failed.
 */
enum AlertDeliveryStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Sent, self::Failed],
            self::Sent, self::Failed => [],
        };
    }
}
