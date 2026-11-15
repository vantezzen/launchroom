<?php

declare(strict_types=1);

namespace App\Console\Commands\Moves;

use App\Actions\Moves\AdvanceMoves;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:moves:tick')]
#[Description('Move server moves and the control-plane move forward (docs/14 §13)')]
final class MovesTickCommand extends Command
{
    public function handle(AdvanceMoves $moves): int
    {
        $moves->handle();

        return self::SUCCESS;
    }
}
