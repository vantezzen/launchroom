<?php

declare(strict_types=1);

namespace App\Console\Commands\Dockerfiles;

use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Jobs\Dockerfiles\AdvanceDockerfileGenerationJob;
use App\Models\Dockerfiles\DockerfileGeneration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:dockerfile-generations:sweep')]
#[Description('Re-advance running Dockerfile generations (missed task events, step timeouts)')]
final class SweepDockerfileGenerationsCommand extends Command
{
    public function handle(): int
    {
        DockerfileGeneration::query()
            ->whereIn('status', DockerfileGenerationStatus::runningValues())
            ->pluck('id')
            ->each(static function (mixed $id): void {
                if (is_int($id)) {
                    dispatch(new AdvanceDockerfileGenerationJob($id));
                }
            });

        return self::SUCCESS;
    }
}
