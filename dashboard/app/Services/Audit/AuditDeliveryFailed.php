<?php

declare(strict_types=1);

namespace App\Services\Audit;

use RuntimeException;

/**
 * A sink refused or couldn't take a batch. The message is safe to show and
 * store: never a URL, credential or response body.
 */
final class AuditDeliveryFailed extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }

    public static function status(int $status): self
    {
        return new self(match (true) {
            $status === 401 || $status === 403 => "The destination answered {$status}: check its credentials and permissions.",
            $status === 404 => 'The destination answered 404: check the address or bucket.',
            $status === 429 => 'The destination is rate limiting us (429). We will try again.',
            default => "The destination answered {$status}.",
        });
    }
}
