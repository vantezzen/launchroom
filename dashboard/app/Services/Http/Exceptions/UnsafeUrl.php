<?php

declare(strict_types=1);

namespace App\Services\Http\Exceptions;

use RuntimeException;

/**
 * The URL was rejected before any connection was made. Messages are safe to
 * show to the user who supplied the URL.
 */
final class UnsafeUrl extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("This URL can't be used: {$reason}.");
    }
}
