<?php

declare(strict_types=1);

namespace App\Support\Logs;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;

/**
 * Where a log search looks: one environment (the Logs tab, live tails),
 * every environment of a project, or the whole team (owners and admins).
 * Built only from models the caller already authorized.
 */
final readonly class LogScope
{
    private function __construct(
        public string $kind,
        public int $teamId,
        public ?int $projectId = null,
        public ?int $environmentId = null,
    ) {}

    public static function environment(Environment $environment): self
    {
        return new self('environment', $environment->team_id, $environment->project_id, $environment->id);
    }

    public static function project(Project $project): self
    {
        return new self('project', $project->team_id, $project->id);
    }

    public static function team(Team $team): self
    {
        return new self('team', $team->id);
    }
}
