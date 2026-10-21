<?php

declare(strict_types=1);

namespace App\Services\Mesh\Exceptions;

use RuntimeException;

/**
 * Headscale could not be reached or refused a request. The message is safe to
 * show to the instance owner: it never contains keys.
 */
final class HeadscaleUnavailable extends RuntimeException
{
    public static function because(string $operation, string $reason): self
    {
        return new self("Headscale {$operation} failed: {$reason}");
    }
}
