<?php

declare(strict_types=1);

namespace App\Actions\Moves;

/**
 * Moves every open move forward (docs/14 §13): server moves, then the
 * control-plane move. Called by `launchroom:moves:tick` every minute and
 * right after something a server move waits for finished.
 */
final readonly class AdvanceMoves
{
    public function __construct(
        private AdvanceServerMoves $servers,
        private AdvanceControlPlaneMoves $controlPlane,
    ) {}

    public function handle(): void
    {
        $this->servers->handle();
        $this->controlPlane->handle();
    }
}
