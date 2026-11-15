<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\Http\WebhookPostFailure;
use App\Services\Http\Exceptions\WebhookPostFailed;
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

    public static function from(WebhookPostFailed $e, bool $allowPrivateNetwork): self
    {
        return match ($e->failure) {
            WebhookPostFailure::UnsafeUrl => self::because($allowPrivateNetwork
                ? 'This address is not allowed.'
                : 'This address is not allowed: it must be https and reachable on the public internet.'),
            WebhookPostFailure::ResponseTooLarge => self::because('The destination answered with an unexpectedly large response.'),
            WebhookPostFailure::Unreachable, WebhookPostFailure::Failed => self::because("Could not reach {$e->host}."),
            WebhookPostFailure::Status => self::status((int) $e->status),
        };
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
