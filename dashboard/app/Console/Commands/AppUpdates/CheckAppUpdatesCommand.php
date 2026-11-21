<?php

declare(strict_types=1);

namespace App\Console\Commands\AppUpdates;

use App\Actions\AppUpdates\QueueAppUpdateChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:app-updates:check')]
#[Description('Queue the version checks of image services that are due (docs/04 §14.1)')]
final class CheckAppUpdatesCommand extends Command
{
    public function handle(QueueAppUpdateChecks $queue): int
    {
        $this->info($queue->handle().' checks queued.');

        return self::SUCCESS;
    }
}
