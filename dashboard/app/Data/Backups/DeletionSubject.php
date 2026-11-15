<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Models\Backups\DeletionRequest;
use App\Models\Environment;
use App\Models\Project;

/**
 * The environment or project a deletion removes (docs/14 §9.13).
 */
final readonly class DeletionSubject
{
    /**
     * @param  'environment'|'project'  $type
     */
    public function __construct(
        public string $type,
        public int $id,
        public string $publicId,
        public string $name,
        public int $teamId,
    ) {}

    public static function environment(Environment $environment): self
    {
        return new self(DeletionRequest::ENVIRONMENT, $environment->id, $environment->public_id, $environment->name, $environment->team_id);
    }

    public static function project(Project $project): self
    {
        return new self(DeletionRequest::PROJECT, $project->id, $project->public_id, $project->name, $project->team_id);
    }
}
