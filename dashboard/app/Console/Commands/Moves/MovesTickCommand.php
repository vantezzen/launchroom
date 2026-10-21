<?php

declare(strict_types=1);

namespace App\Console\Commands\Moves;

use App\Actions\Moves\AdvanceControlPlaneMoves;
use App\Actions\Moves\AdvanceServerMoves;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:moves:tick')]
#[Description('Move server moves and the control-plane move forward (docs/14 §13)')]
final class MovesTickCommand extends Command
{
    public function handle(AdvanceServerMoves $moves, AdvanceControlPlaneMoves $controlPlane): int
    {
        $moves->handle();
        $controlPlane->handle();

        return self::SUCCESS;
    }
}
