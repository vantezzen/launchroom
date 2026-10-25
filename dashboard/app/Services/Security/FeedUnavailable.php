<?php

declare(strict_types=1);

namespace App\Services\Security;

use RuntimeException;

/**
 * An advisory source couldn't be reached or asked us to slow down (429,
 * 5xx, connection errors). The caller backs off and resumes later; data
 * already stored stays as it is (docs/22 §4.4).
 */
final class FeedUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}
