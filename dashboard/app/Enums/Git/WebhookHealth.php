<?php

declare(strict_types=1);

namespace App\Enums\Git;

/**
 * Whether webhooks reach the dashboard. "stale" and "never" turn on the
 * polling fallback for tracked refs (docs/06 §2).
 */
enum WebhookHealth: string
{
    case Healthy = 'healthy';
    case Stale = 'stale';
    case Never = 'never';
}
