<?php

declare(strict_types=1);

namespace App\Jobs\Updates;

use App\Actions\Updates\RunControlPlaneUpdate;
use App\Models\Updates\ControlPlaneUpdate;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;

/**
 * Pre-upgrade backup and the self_update task of a control-plane update
 * (docs/11 §4), off the request path. Not retried blindly: the action only
 * starts pending updates, and the scheduler re-dispatches a lost one.
 */
#[Tries(1)]
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
