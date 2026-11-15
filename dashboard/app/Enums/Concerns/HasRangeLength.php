<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * The length of a time-range enum case, read from its value (`15m`, `6h`,
 * `7d`, `1y`). The observe scope in the URL uses the same notation, and
 * lib/observe-scope.ts reads it with the same rule (docs/08 §1).
 */
trait HasRangeLength
{
    private const array UNIT_SECONDS = ['m' => 60, 'h' => 3_600, 'd' => 86_400, 'y' => 365 * 86_400];

    public function seconds(): int
    {
        if (preg_match('/^([1-9]\d*)([mhdy])$/D', $this->value, $match) !== 1) {
            throw new \LogicException("Range {$this->value} has no length.");
        }

        return (int) $match[1] * self::UNIT_SECONDS[$match[2]];
    }
}
