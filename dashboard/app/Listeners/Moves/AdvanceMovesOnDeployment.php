<?php

declare(strict_types=1);

namespace App\Listeners\Moves;

use App\Enums\DeploymentStatus;
use App\Enums\Moves\ServerMoveStatus;
use App\Events\DeploymentStatusChanged;
use App\Jobs\Moves\AdvanceServerMovesJob;
use App\Models\Moves\ServerMove;

/**
 * A deployment finished (active, failed, superseded): a server move waiting
 * for it on the target (or back on the source) continues right away
 * (docs/14 §13.2).
 */
final class AdvanceMovesOnDeployment
{
    public function handle(DeploymentStatusChanged $event): void
    {
        if (! in_array($event->to, [DeploymentStatus::Active, DeploymentStatus::Failed, DeploymentStatus::Cancelled, DeploymentStatus::Superseded], true)) {
            return;
        }

        if (ServerMove::query()->whereIn('status', ServerMoveStatus::values([ServerMoveStatus::Running, ServerMoveStatus::Cancelling]))->exists()) {
            dispatch(new AdvanceServerMovesJob);
        }
    }
}
