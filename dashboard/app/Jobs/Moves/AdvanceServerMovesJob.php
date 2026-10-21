<?php

declare(strict_types=1);

namespace App\Jobs\Moves;

use App\Actions\Moves\AdvanceControlPlaneMoves;
use App\Actions\Moves\AdvanceServerMoves;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Moves open server moves forward right after something they wait for
 * changed (a task or a deployment finished), instead of at the next tick
 * (docs/14 §13.2). Idempotent; one queued at a time.
 */
#[Tries(3)]
#[Backoff([5, 15])]
#[Timeout(120)]
final class AdvanceServerMovesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(AdvanceServerMoves $moves, AdvanceControlPlaneMoves $controlPlane): void
    {
        $moves->handle();
        $controlPlane->handle();
    }
}
