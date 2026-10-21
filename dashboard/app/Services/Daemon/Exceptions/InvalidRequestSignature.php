<?php

declare(strict_types=1);

namespace App\Services\Daemon\Exceptions;

use RuntimeException;

/**
 * A daemon request failed signature verification. The message names the
 * failing step for logs; clients only ever see a generic 401.
 */
final class InvalidRequestSignature extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
