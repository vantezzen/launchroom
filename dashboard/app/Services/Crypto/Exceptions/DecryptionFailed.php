<?php

declare(strict_types=1);

namespace App\Services\Crypto\Exceptions;

use RuntimeException;

/**
 * Deliberately vague: never reveals which check failed beyond the category,
 * and never includes key material or plaintext.
 */
final class DecryptionFailed extends RuntimeException
{
    public static function unsupportedFormat(): self
    {
        return new self('Ciphertext has an unsupported format.');
    }

    public static function malformed(): self
    {
        return new self('Ciphertext is malformed.');
    }

    public static function authenticationFailed(): self
    {
        return new self('Ciphertext could not be authenticated (wrong key, wrong context or tampered).');
    }
}
