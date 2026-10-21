<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use SensitiveParameter;

/**
 * `X-Hub-Signature-256` verification (docs/09 R17): HMAC-SHA256 over the raw
 * request body, compared with hash_equals, and **fail closed**: a missing or
 * empty secret or header is never a match (Coolify's `hash_hmac(…, null)` CVE).
 */
final class WebhookSignature
{
    public static function isValid(string $rawBody, ?string $header, #[SensitiveParameter] ?string $secret): bool
    {
        if ($secret === null || $secret === '' || $header === null || $header === '') {
            return false;
        }

        if (preg_match('/^sha256=([0-9a-fA-F]{64})$/D', $header, $matches) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($matches[1]));
    }
}
