<?php

declare(strict_types=1);

namespace App\Console\Commands\AppUpdates;

use App\Actions\AppUpdates\AdvanceAppUpdates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:app-updates:tick')]
#[Description('Advance app updates and start automatic ones in their window (docs/04 §14.2)')]
final class TickAppUpdatesCommand extends Command
{
    public function handle(AdvanceAppUpdates $advance): int
    {
        $advance->handle();

        return self::SUCCESS;
    }
}
