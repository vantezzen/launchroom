<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * Shared prop for the sidebar's "Get started" item (instance owners only).
 */
final class OnboardingProgressData extends Data
{
    public function __construct(
        /** Steps done or skipped. */
        public int $done,
        public int $total,
        public bool $complete,
        public bool $dismissed,
    ) {}
}
