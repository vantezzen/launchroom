<?php

declare(strict_types=1);

namespace App\Console\Commands\Updates;

use App\Actions\Updates\RunAutomaticUpdate;
use App\Models\Updates\ControlPlaneUpdate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled hourly (docs/11 §4): installs a newer release when automatic
 * updates are on and it is night on the control-plane server.
 */
#[Signature('launchroom:updates:auto')]
#[Description('Start an automatic update when one is due')]
final class AutoUpdateCommand extends Command
{
    public function handle(RunAutomaticUpdate $run): int
    {
        $result = $run->handle();

        $this->line($result instanceof ControlPlaneUpdate
            ? "Started the automatic update to {$result->to_version}."
            : "No automatic update: {$result}.");

        return self::SUCCESS;
    }
}
