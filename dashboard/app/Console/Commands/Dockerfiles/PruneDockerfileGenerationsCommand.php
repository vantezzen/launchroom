<?php

declare(strict_types=1);

namespace App\Console\Commands\Dockerfiles;

use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\ServiceInstance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:dockerfile-generations:prune')]
#[Description('Delete finished Dockerfile generations nobody uses (and the repository context they kept)')]
final class PruneDockerfileGenerationsCommand extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDays(config()->integer('launchroom.dockerfile_generation.prune_after_days'));
        $deleted = 0;

        DockerfileGeneration::query()
            ->whereNotIn('status', DockerfileGenerationStatus::runningValues())
            ->whereNull('accepted_at')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($generations) use (&$deleted): void {
                foreach ($generations as $generation) {
                    /** @var DockerfileGeneration $generation */
                    // Still referenced by a service's build settings (current or staged)? Keep it.
                    $used = ServiceInstance::query()->whereRaw("build->>'generatedDockerfile' = ?", [$generation->public_id])->exists();

                    if (! $used) {
                        $generation->delete();
                        $deleted++;
                    }
                }
            });

        // Accepted ones keep their Dockerfile; the repository context goes.
        DockerfileGeneration::query()
            ->whereNotNull('accepted_at')
            ->whereNotNull('context')
            ->where('created_at', '<', $cutoff)
            ->update(['context' => null]);

        $this->info("Deleted {$deleted} generations.");

        return self::SUCCESS;
    }
}
