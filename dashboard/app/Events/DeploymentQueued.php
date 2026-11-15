<?php

declare(strict_types=1);

namespace App\Events;

use App\Services\Deployments\DeploymentFactory;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A deployment was created ({@see DeploymentFactory}). Dispatched after the
 * surrounding transaction commits, so the deployment is visible to the job
 * that starts it.
 */
final readonly class DeploymentQueued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $deploymentId,
    ) {}
}
