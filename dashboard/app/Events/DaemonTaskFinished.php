<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A daemon task reached a terminal status (succeeded, failed, cancelled),
 * whether reported by the daemon, expired on its last lease or cancelled by
 * the dashboard. Dispatched after commit; listeners advance the deployment
 * the task belongs to and record plan previews.
 */
final readonly class DaemonTaskFinished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $taskId,
    ) {}
}
