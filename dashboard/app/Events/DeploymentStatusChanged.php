<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DeploymentStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A deployment changed status (docs/04 §6). Dispatched after the surrounding
 * transaction commits; listeners (GitHub statuses, notifications, broadcasts)
 * only run side effects and never change the deployment's outcome.
 */
final readonly class DeploymentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $deploymentId,
        public DeploymentStatus $from,
        public DeploymentStatus $to,
    ) {}
}
