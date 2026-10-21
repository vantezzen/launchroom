<?php

declare(strict_types=1);

namespace App\Services\Http\Exceptions;

use RuntimeException;

final class ResponseTooLarge extends RuntimeException
{
    public static function exceeds(int $maxBytes): self
    {
        return new self("The response exceeded the maximum size of {$maxBytes} bytes.");
    }
}
