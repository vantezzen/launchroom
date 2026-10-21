<?php

declare(strict_types=1);

namespace App\Support\Deployments;

use App\Support\Images\ImageReference;

/**
 * Results of the slow lookups a queued deployment needs (registry tag →
 * digest, branch → head commit), made by AdvanceDeployment BEFORE it takes
 * the deployment's row lock, so no HTTP call runs inside the locked
 * transaction. Under the lock the deployment is re-checked and these are
 * used only if it still needs them.
 */
final readonly class PrefetchedInputs
{
    public function __construct(
        public ?ImageReference $image = null,
        public ?string $imageError = null,
        public ?GitHeadLookup $head = null,
    ) {}
}
