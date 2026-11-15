<?php

declare(strict_types=1);

namespace App\Support\Json;

use RuntimeException;

/**
 * A provider sent JSON we can't interpret. Messages name the field, never
 * echo values.
 */
final class InvalidJsonPayload extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("Unexpected JSON payload: {$reason}.");
    }

    public static function field(string $key, string $expected): self
    {
        return new self("Unexpected JSON payload: \"{$key}\" is not {$expected}.");
    }
}
