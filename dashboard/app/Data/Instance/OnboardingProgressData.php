<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * Where the first-run checklist stands (docs/08 §3, D109): the sidebar's
 * "Get started" (shared prop, instance owners only) and the page's ring.
 * A skipped step is not done: `done` counts done steps only.
 */
final class OnboardingProgressData extends Data
{
    public function __construct(
        /** Steps really done. */
        public int $done,
        /** Steps skipped (they stay optional). */
        public int $skipped,
        public int $total,
        /** Nothing left but skipped steps: the redirect stops, What's next shows, the sidebar entry can be removed. */
        public bool $settled,
        /** Every step done: the sidebar entry goes away by itself. */
        public bool $complete,
        public bool $dismissed,
    ) {}
}
