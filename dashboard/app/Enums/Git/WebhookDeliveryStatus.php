<?php

declare(strict_types=1);

namespace App\Enums\Git;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Processing state of a verified GitHub webhook delivery (docs/07
 * `webhook_deliveries`). A failed delivery may be retried by the queue.
 */
enum WebhookDeliveryStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Received, self::Failed => [self::Processed, self::Ignored, self::Failed],
            self::Processed, self::Ignored => [],
        };
    }
}
