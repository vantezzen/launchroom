<?php

declare(strict_types=1);

namespace App\Contracts\Git;

use App\Data\Git\TrackedRef;
use App\Services\GitHub\NullTrackedRefs;

/**
 * The refs launchroom deploys from: the polling fallback (docs/06 §2) checks
 * these when webhooks are unhealthy. The core domain implements it from
 * environments' triggers and services' Git sources; until then
 * {@see NullTrackedRefs} is bound.
 */
interface TrackedRefs
{
    /**
     * Distinct (installation, repository, ref) tuples to watch.
     *
     * @return iterable<TrackedRef>
     */
    public function all(): iterable;
}
