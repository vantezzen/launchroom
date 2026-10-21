<?php

declare(strict_types=1);

namespace App\Exceptions\Daemon;

use RuntimeException;

/**
 * POST /daemon/v1/client-certificate was refused (docs/03 §4.3). The
 * message is the response's `error`; the status is the HTTP status.
 */
final class ClientCertificateRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function invalidKey(): self
    {
        return new self('the public key must be an ECDSA P-256 SubjectPublicKeyInfo', 422);
    }

    public static function certificateRequired(): self
    {
        return new self('client certificate required', 403);
    }

    public static function throttled(): self
    {
        return new self('too many certificates for this server, try again later', 429);
    }
}
