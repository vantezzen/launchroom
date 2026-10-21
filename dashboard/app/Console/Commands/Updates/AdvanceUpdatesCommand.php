<?php

declare(strict_types=1);

namespace App\Console\Commands\Updates;

use App\Actions\Updates\AdvanceUpdates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every minute (docs/11 §4): records finished self_update tasks of
 * control-plane and server updates and starts the next server.
 */
#[Signature('launchroom:updates:advance')]
#[Description('Advance running control-plane and server updates')]
final class AdvanceUpdatesCommand extends Command
{
    public function handle(AdvanceUpdates $advance): int
    {
        $advance->handle();

        return self::SUCCESS;
    }
}
