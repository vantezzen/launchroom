<?php

declare(strict_types=1);

namespace App\Actions\Dockerfiles;

use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\ServiceInstance;
use Illuminate\Database\Eloquent\Collection;

/**
 * Deletes finished generations nobody uses after
 * `dockerfile_generation.prune_after_days`; accepted ones keep their
 * Dockerfile and lose only the repository context they kept.
 */
final class PruneDockerfileGenerations
{
    /**
     * @return int the number of deleted generations
     */
    public function handle(): int
    {
        $cutoff = now()->subDays(config()->integer('launchroom.dockerfile_generation.prune_after_days'));
        $deleted = 0;

        DockerfileGeneration::query()
            ->whereNotIn('status', DockerfileGenerationStatus::runningValues())
            ->whereNull('accepted_at')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function (Collection $generations) use (&$deleted): void {
                foreach ($generations as $generation) {
                    if (! $this->referenced($generation)) {
                        $generation->delete();
                        $deleted++;
                    }
                }
            });

        DockerfileGeneration::query()
            ->whereNotNull('accepted_at')
            ->whereNotNull('context')
            ->where('created_at', '<', $cutoff)
            ->update(['context' => null]);

        return $deleted;
    }

    /**
     * Still chosen in a service's build settings, current or staged.
     */
    private function referenced(DockerfileGeneration $generation): bool
    {
        return ServiceInstance::query()->whereRaw("build->>'generatedDockerfile' = ?", [$generation->public_id])->exists();
    }
}
