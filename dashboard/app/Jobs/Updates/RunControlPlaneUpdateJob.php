<?php

declare(strict_types=1);

namespace App\Jobs\Updates;

use App\Actions\Updates\AdvanceUpdates;
use App\Actions\Updates\RunControlPlaneUpdate;
use App\Models\Updates\ControlPlaneUpdate;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

/**
 * Pre-upgrade backup and the self_update task of a control-plane update
 * (docs/11 §4), off the request path, on the long lane (config/queue.php
 * `routes`: its retry_after and the Horizon supervisor's timeout are above
 * this job's timeout, so a busy worker is never stopped or doubled).
 *
 * Not retried blindly: the action only continues an update that has no
 * self_update task yet, and {@see AdvanceUpdates} dispatches it again when
 * it shows no progress (docs/11 §4.3). The unique lock only keeps those
 * re-dispatches from queueing copies; it expires (#[UniqueFor]) because a
 * worker that dies never releases it, and the action's row locks keep a
 * later copy safe.
 */
#[Tries(1)]
#[UniqueFor(600)]
final class RunControlPlaneUpdateJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout;

    public function __construct(
        public readonly int $updateId,
    ) {
        // The pre-upgrade backup runs in this job.
        $this->timeout = config()->integer('launchroom.backups.task_timeout_seconds') + 300;
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->updateId;
    }

    public function handle(RunControlPlaneUpdate $action): void
    {
        $update = ControlPlaneUpdate::query()->find($this->updateId);

        if ($update instanceof ControlPlaneUpdate) {
            $action->handle($update);
        }
    }
}
