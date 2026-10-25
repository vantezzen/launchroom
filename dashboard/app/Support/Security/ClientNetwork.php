<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * A firewall event's client network (docs/22 §6.5, `state.ClientNetwork`):
 * the canonical /24 (IPv4) or /48 (IPv6) prefix, never a full address.
 * Checked again here so nothing else reaches the database. Pure.
 */
final class ClientNetwork
{
    public static function isValid(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 43) {
            return false;
        }

        if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.0\/24$/D', $value, $m) === 1) {
            return array_all([$m[1], $m[2], $m[3]], fn (string $octet): bool => (int) $octet <= 255 && ($octet === '0' || ! str_starts_with($octet, '0')));
        }

        if (preg_match('/^([0-9a-f:]{2,39})\/48$/D', $value, $m) !== 1) {
            return false;
        }

        $packed = @inet_pton($m[1]);

        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        // IPv4-mapped addresses count as IPv4 (launchroomd unmaps them).
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return false;
        }

        $masked = substr($packed, 0, 6).str_repeat("\0", 10);

        return $masked === $packed && inet_ntop($masked) === $m[1];
    }
}
