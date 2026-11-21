<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Commands\PruneCommandRuns;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:commands:prune')]
#[Description('Delete one-off command runs and their output past their retention')]
final class PruneCommandRunsCommand extends Command
{
    public function handle(PruneCommandRuns $prune): int
    {
        $this->components->info(sprintf('Pruned %d command runs.', $prune->handle()));

        return self::SUCCESS;
    }
}
