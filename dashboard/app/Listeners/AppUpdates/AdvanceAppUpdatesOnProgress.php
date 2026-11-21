<?php

declare(strict_types=1);

namespace App\Listeners\AppUpdates;

use App\Events\Backups\BackupRunFinished;
use App\Events\DeploymentStatusChanged;
use App\Jobs\AppUpdates\AdvanceAppUpdateJob;
use App\Models\AppUpdates\AppUpdate;

/**
 * A backup run or a deployment moved on: an app update waiting for it
 * continues right away instead of at the next tick (docs/04 §14.2).
 */
final class AdvanceAppUpdatesOnProgress
{
    public function handle(BackupRunFinished|DeploymentStatusChanged $event): void
    {
        if ($event instanceof DeploymentStatusChanged && ! $event->to->isFinished()) {
            return;
        }

        $teamId = $event instanceof BackupRunFinished ? $event->teamId : null;
        /** @var list<int> $updates */
        $updates = AppUpdate::query()->open()->when($teamId !== null, static fn ($query) => $query->where('team_id', $teamId))->pluck('id')->all();

        foreach ($updates as $id) {
            dispatch(new AdvanceAppUpdateJob($id));
        }
    }
}
