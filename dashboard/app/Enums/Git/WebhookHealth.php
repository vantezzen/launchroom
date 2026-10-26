<?php

declare(strict_types=1);

namespace App\Enums\Git;

/**
 * Whether webhooks deliver pushes (docs/06 §2.2). "stale": polling found a
 * push no webhook delivered, and no delivery arrived since; "never": no
 * delivery yet. Both poll tracked refs every minute; "healthy" keeps a
 * safety poll every few minutes.
 */
enum WebhookHealth: string
{
    case Healthy = 'healthy';
    case Stale = 'stale';
    case Never = 'never';
}
