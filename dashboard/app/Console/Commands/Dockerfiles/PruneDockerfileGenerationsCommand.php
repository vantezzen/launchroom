<?php

declare(strict_types=1);

namespace App\Console\Commands\Dockerfiles;

use App\Actions\Dockerfiles\PruneDockerfileGenerations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:dockerfile-generations:prune')]
#[Description('Delete finished Dockerfile generations nobody uses (and the repository context they kept)')]
final class PruneDockerfileGenerationsCommand extends Command
{
    public function handle(PruneDockerfileGenerations $prune): int
    {
        $deleted = $prune->handle();

        $this->info("Deleted {$deleted} generations.");

        return self::SUCCESS;
    }
}
