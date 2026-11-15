<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Support\Crypto\OpaqueToken;
use SensitiveParameter;

/**
 * The signature of outbound webhooks, alert notifications (docs/15 §3.5)
 * and audit batches (docs/09 R34):
 * `X-Launchroom-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>`.
 * Receivers recompute it over the raw body, compare in constant time and
 * reject timestamps older than 5 minutes. verify() is the reference
 * implementation (and what the tests use).
 */
final class WebhookSignature
{
    public const int TOLERANCE_SECONDS = 300;

    /**
     * A fresh signing secret: `lrwh_` + 256 bits, URL-safe (the documented
     * secret format receivers configure).
     */
    public static function generateSecret(): string
    {
        return OpaqueToken::generate('lrwh_');
    }

    public static function header(#[SensitiveParameter] string $secret, int $timestamp, string $body): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function verify(#[SensitiveParameter] string $secret, string $header, string $body, int $now): bool
    {
        if ($secret === '' || preg_match('/^t=(\d{1,12}),v1=([0-9a-f]{64})$/D', $header, $m) !== 1) {
            return false;
        }

        $timestamp = (int) $m[1];

        if (abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $m[2]);
    }
}
