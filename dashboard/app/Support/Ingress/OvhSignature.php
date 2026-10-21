<?php

declare(strict_types=1);

namespace App\Support\Ingress;

use SensitiveParameter;

/**
 * OVHcloud's API request signature (docs/05 §5, D47): "$1$" + hex SHA-1 of
 * secret + consumer key + method + URL + body + timestamp, joined by "+".
 * OVH defines it with SHA-1, so this is the one place SHA-1 is used; it
 * authenticates a request (an HMAC-like construction with the secret as
 * prefix), it is never used to store or compare secrets. The architecture
 * test's security preset allows exactly this class.
 */
final class OvhSignature
{
    public static function sign(#[SensitiveParameter] string $applicationSecret, #[SensitiveParameter] string $consumerKey, string $method, string $url, string $body, string $timestamp): string
    {
        return '$1$'.sha1(implode('+', [$applicationSecret, $consumerKey, $method, $url, $body, $timestamp]));
    }
}
