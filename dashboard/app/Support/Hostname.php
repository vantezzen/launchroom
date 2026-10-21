<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Hostnames for routes (docs/05 §5): RFC 1123, lowercase, labels ≤ 63 chars,
 * no underscores (Let's Encrypt refuses them), total ≤ 253, no numeric TLD
 * (so no IP literals). The NodeState route `hosts` pattern is the same
 * grammar without the TLD rule.
 */
final class Hostname
{
    public const string PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    /**
     * The top-level label must not be all digits: that rejects IP literals
     * (`10.0.0.5`, `127.0.0.1`) and numeric TLDs, which aren't domains
     * (review L9; no public TLD is numeric).
     */
    public static function isValid(string $hostname): bool
    {
        return strlen($hostname) <= 253
            && preg_match(self::PATTERN, $hostname) === 1
            && preg_match('/\.[0-9]+$/D', $hostname) !== 1;
    }

    public static function normalize(string $hostname): string
    {
        $hostname = strtolower(rtrim(trim($hostname), '.'));

        // IDNs are stored as punycode (docs/05 §5).
        if (preg_match('/[^\x00-\x7F]/', $hostname) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($hostname, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $hostname = $ascii === false ? $hostname : $ascii;
        }

        return $hostname;
    }

    /**
     * One DNS label from parts joined by "-": truncated to 63 characters with
     * a stable hash suffix when too long (docs/05 §5).
     */
    public static function label(string ...$parts): string
    {
        $label = trim(implode('-', array_filter($parts, static fn (string $part): bool => $part !== '')), '-');

        if (strlen($label) <= 63) {
            return $label;
        }

        $hash = substr(hash('sha256', $label), 0, 8);

        return rtrim(substr($label, 0, 63 - 9), '-').'-'.$hash;
    }

    public static function isLocal(string $hostname): bool
    {
        return $hostname === 'localhost' || str_ends_with($hostname, '.localhost');
    }
}
