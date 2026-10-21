<?php

declare(strict_types=1);

namespace App\Support\Network;

use InvalidArgumentException;

/**
 * Carves launchroom's environment range (default 10.231.0.0/16) into
 * fixed-size subnets (default /24), one per environment (docs/05 §1). Pure:
 * the caller passes the subnets in use and serializes allocations.
 */
final class SubnetAllocator
{
    /**
     * The lowest free subnet of $range with prefix length $prefix, or null
     * when the range is exhausted.
     *
     * @param  iterable<string>  $used
     */
    public static function next(string $range, int $prefix, iterable $used): ?string
    {
        [$base, $rangePrefix] = self::parse($range);

        if ($prefix < $rangePrefix || $prefix > 30) {
            throw new InvalidArgumentException("Cannot carve /{$prefix} subnets out of {$range}.");
        }

        $taken = [];

        foreach ($used as $subnet) {
            $taken[$subnet] = true;
        }

        $size = 1 << (32 - $prefix);
        $count = 1 << ($prefix - $rangePrefix);

        for ($i = 0; $i < $count; $i++) {
            $candidate = long2ip($base + $i * $size).'/'.$prefix;

            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * How many /$prefix subnets $range holds.
     */
    public static function capacity(string $range, int $prefix): int
    {
        [, $rangePrefix] = self::parse($range);

        if ($prefix < $rangePrefix || $prefix > 30) {
            throw new InvalidArgumentException("Cannot carve /{$prefix} subnets out of {$range}.");
        }

        return 1 << ($prefix - $rangePrefix);
    }

    /**
     * @return array{int, int} network address as an integer, prefix length
     */
    private static function parse(string $cidr): array
    {
        if (preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})\/([0-9]{1,2})$/D', $cidr, $m) !== 1) {
            throw new InvalidArgumentException("Invalid CIDR {$cidr}.");
        }

        $address = ip2long($m[1]);
        $prefix = (int) $m[2];

        if ($address === false || $prefix < 8 || $prefix > 30) {
            throw new InvalidArgumentException("Invalid CIDR {$cidr}.");
        }

        $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;

        return [$address & $mask, $prefix];
    }
}
