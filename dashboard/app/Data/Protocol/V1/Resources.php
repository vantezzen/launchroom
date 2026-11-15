<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Services\ScalingSettings;
use Spatie\LaravelData\Data;

/**
 * Container limits (protocol Resources).
 */
final class Resources extends Data
{
    public function __construct(
        public float $cpus,
        public int $memoryMb,
        public int $pids,
    ) {}

    public static function of(ScalingSettings $scaling): self
    {
        return new self($scaling->cpus, $scaling->memoryMb, $scaling->pids());
    }
}
