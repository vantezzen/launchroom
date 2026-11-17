<?php

declare(strict_types=1);

namespace App\Services\Registry;

use RuntimeException;

/**
 * A private registry refused or failed a request. The message is written for
 * people (it ends up on a deployment and in Settings → Private registries) and
 * never contains a password, a token or a URL with credentials.
 */
final class UpstreamRegistryError extends RuntimeException
{
    public const string UNAUTHORIZED = 'unauthorized';

    public const string FORBIDDEN = 'forbidden';

    public const string NOT_FOUND = 'not_found';

    public const string RATE_LIMITED = 'rate_limited';

    public const string UNREACHABLE = 'unreachable';

    public const string INVALID = 'invalid';

    public function __construct(
        public readonly string $kind,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function isSignInProblem(): bool
    {
        return $this->kind === self::UNAUTHORIZED;
    }
}
