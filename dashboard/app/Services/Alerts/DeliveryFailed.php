<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use RuntimeException;

/**
 * A channel refused or couldn't take a message. The message is safe to show
 * and store: it never contains the channel's URL (which carries its token).
 */
final class DeliveryFailed extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }

    public static function status(int $status): self
    {
        return new self(match (true) {
            $status === 404 || $status === 410 => "The address answered {$status}: the webhook was probably deleted.",
            $status === 401 || $status === 403 => "The address answered {$status}: it no longer accepts messages from here.",
            $status === 429 => 'The service is rate limiting messages (429). launchroom will try again.',
            default => "The service answered {$status}.",
        });
    }
}
