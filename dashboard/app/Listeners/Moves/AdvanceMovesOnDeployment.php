<?php

declare(strict_types=1);

namespace App\Listeners\Moves;

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
        if (! $event->to->isFinished()) {
            return;
        }

        if (ServerMove::query()->advancing()->exists()) {
            dispatch(new AdvanceServerMovesJob);
        }
    }
}
