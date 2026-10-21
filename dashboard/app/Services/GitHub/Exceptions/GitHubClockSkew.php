<?php

declare(strict_types=1);

namespace App\Services\GitHub\Exceptions;

use RuntimeException;

/**
 * GitHub rejected the App JWT and its Date header shows our clock is off.
 * Instead of probing `/zen` before every JWT, we read the Date header of
 * responses we get anyway.
 */
final class GitHubClockSkew extends RuntimeException
{
    public static function of(int $seconds): self
    {
        return new self("GitHub rejected the app token: this server's clock differs from GitHub's by {$seconds} seconds. Check that NTP is running.");
    }
}
