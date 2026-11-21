<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * Get started's "What's next" (docs/08 §3, D109): only what the owner may
 * do and hasn't done yet. ⌘K is always offered, so it has no flag.
 */
final class OnboardingNextData extends Data
{
    public function __construct(
        /** The first app has no custom domain yet and the owner may change it. */
        public bool $customDomain,
        /** The owner is alone in the team and may invite. */
        public bool $invite,
    ) {}
}
