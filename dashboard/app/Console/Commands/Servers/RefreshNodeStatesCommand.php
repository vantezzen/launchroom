<?php

declare(strict_types=1);

namespace App\Console\Commands\Servers;

use App\Actions\Servers\RefreshNodeStates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:node-states:refresh')]
#[Description('Re-render every NodeState when system image pins or other instance-wide inputs changed (after an update)')]
final class RefreshNodeStatesCommand extends Command
{
    public function handle(RefreshNodeStates $refresh): int
    {
        $queued = $refresh->handle();

        if ($queued > 0) {
            $this->components->info("Queued a NodeState render for {$queued} servers.");
        }

        return self::SUCCESS;
    }
}
