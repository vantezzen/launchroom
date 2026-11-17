<?php

declare(strict_types=1);

namespace App\Data\Instance\System;

use Spatie\LaravelData\Data;

/**
 * A kind of job that failed in the last 24 hours (class name only: an
 * exception message can carry anything, so it stays in Horizon).
 */
final class SystemFailedJobData extends Data
{
    public function __construct(
        public string $job,
        public int $count,
        public string $lastFailedAt,
    ) {}
}
