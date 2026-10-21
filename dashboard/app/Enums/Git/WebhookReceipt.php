<?php

declare(strict_types=1);

namespace App\Enums\Git;

/**
 * Outcome of receiving a webhook, mapped to the HTTP status GitHub sees.
 */
enum WebhookReceipt: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Invalid = 'invalid';
    case Unauthorized = 'unauthorized';

    public function httpStatus(): int
    {
        return match ($this) {
            self::Accepted, self::Duplicate => 202,
            self::Invalid => 400,
            // docs/09 R17: 401 (not 200) on failed verification.
            self::Unauthorized => 401,
        };
    }
}
