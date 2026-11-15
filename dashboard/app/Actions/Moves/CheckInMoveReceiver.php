<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Models\Moves\ControlPlaneMove;

/**
 * The new machine of a control-plane move asked how its move is doing
 * (docs/14 §13.4): it was seen now, and a joining move becomes joined as soon
 * as its launchroomd enrolled.
 */
final readonly class CheckInMoveReceiver
{
    public function __construct(
        private AdvanceControlPlaneMoves $advance,
    ) {}

    public function handle(ControlPlaneMove $move): ControlPlaneMove
    {
        $move->forceFill(['receiver_seen_at' => now()])->save();

        if ($move->status === ControlPlaneMoveStatus::Joining) {
            $this->advance->handle();
            $move->refresh();
        }

        return $move;
    }
}
