<?php

declare(strict_types=1);

namespace App\Services\Dockerfiles;

use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Dockerfiles\DockerfileGeneration;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Resolves `build.generatedDockerfile` (docs/20 §5) to the Dockerfile a
 * build sends inline: only a verified generation of the same team and
 * service, so a copied or promoted reference can't pull another service's
 * file. Verified generations are immutable.
 */
final readonly class GeneratedDockerfiles
{
    /**
     * @throws DomainRuleViolation
     */
    public function contentFor(int $teamId, string $servicePublicId, string $generationId): string
    {
        $generation = DockerfileGeneration::query()
            ->where('team_id', $teamId)
            ->where('public_id', $generationId)
            ->where('status', DockerfileGenerationStatus::Verified->value)
            ->whereHas('service', static fn (Builder $query) => $query->where('public_id', $servicePublicId))
            ->first();

        if (! $generation instanceof DockerfileGeneration || $generation->dockerfile === null) {
            throw DomainRuleViolation::on('build.generatedDockerfile', 'The Dockerfile stored for this service is gone. Generate a new one or pick another builder in Settings → Build.');
        }

        return $generation->dockerfile;
    }
}
