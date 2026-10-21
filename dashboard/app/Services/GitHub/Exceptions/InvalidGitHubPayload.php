<?php

declare(strict_types=1);

namespace App\Services\GitHub\Exceptions;

use RuntimeException;

/**
 * GitHub sent something we can't interpret. Messages name the field, never
 * echo values.
 */
final class InvalidGitHubPayload extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("Unexpected GitHub payload: {$reason}.");
    }

    public static function field(string $key, string $expected): self
    {
        return new self("Unexpected GitHub payload: \"{$key}\" is not {$expected}.");
    }
}
