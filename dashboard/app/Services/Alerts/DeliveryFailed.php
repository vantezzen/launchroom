<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\Http\WebhookPostFailure;
use App\Services\Http\Exceptions\WebhookPostFailed;
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

    public static function from(WebhookPostFailed $e): self
    {
        return match ($e->failure) {
            WebhookPostFailure::UnsafeUrl => self::because('This address is not allowed: it must be https and reachable on the public internet.'),
            WebhookPostFailure::ResponseTooLarge => self::because('The service answered with an unexpectedly large response.'),
            WebhookPostFailure::Unreachable => self::because("Could not connect to {$e->host}."),
            WebhookPostFailure::Failed => self::because("Sending to {$e->host} failed."),
            WebhookPostFailure::Status => self::status((int) $e->status),
        };
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
