<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The model provider gave no usable draft (unreachable, refused, invalid
 * key, malformed output). The message is user-facing and never contains the
 * API key or the request.
 */
final class DockerfileModelFailed extends RuntimeException
{
    public function __construct(
        string $message,
        /** Worth another attempt with the same input (overload, timeout, malformed output). */
        public readonly bool $retryable = false,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {
        parent::__construct($message);
    }
}
