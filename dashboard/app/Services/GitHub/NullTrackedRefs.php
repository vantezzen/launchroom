<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Contracts\Git\TrackedRefs;

/**
 * Watches nothing. Replaced by the core domain's implementation (services
 * with a Git source × environment triggers).
 */
final class NullTrackedRefs implements TrackedRefs
{
    public function all(): iterable
    {
        return [];
    }
}
