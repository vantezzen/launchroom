<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An API refusal with its own error code (docs/16 §2.1), e.g. the CLI
 * sign-in's `authorization_pending` or an idempotency conflict. The message
 * is user-facing.
 */
final class ApiError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
