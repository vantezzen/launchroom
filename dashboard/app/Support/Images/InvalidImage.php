<?php

declare(strict_types=1);

namespace App\Support\Images;

use RuntimeException;

/**
 * An image we refuse to store or serve. The message is safe to show.
 */
final class InvalidImage extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
