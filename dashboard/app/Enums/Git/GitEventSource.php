<?php

declare(strict_types=1);

namespace App\Enums\Git;

/**
 * Where a git event came from: a verified webhook or the polling fallback
 * (docs/06 §2).
 */
enum GitEventSource: string
{
    case Webhook = 'webhook';
    case Polling = 'polling';
}
