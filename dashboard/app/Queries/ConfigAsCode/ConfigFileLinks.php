<?php

declare(strict_types=1);

namespace App\Queries\ConfigAsCode;

use App\Data\ConfigAsCode\ConfigFileProblemData;
use App\Data\Git\RepositoryRef;
use App\Data\Services\GitSource;
use App\Enums\ServiceKind;
use App\Models\Project;
use App\Models\Service;
use App\Services\Git\GitProviders;

/**
 * Links to a launchroom.json at the commit it was read from (docs/18 §6),
 * built by the provider of the project's app sourced from that repository,
 * so GitLab, Gitea and GitHub Enterprise files link to their own host.
 */
final readonly class ConfigFileLinks
{
    public function __construct(
        private GitProviders $providers,
    ) {}

    public function url(Project $project, ?string $repository, ?string $commitSha, string $path, ?int $line = null): ?string
    {
        $source = $this->source($project, $repository);

        if (! $source instanceof GitSource || $commitSha === null) {
            return null;
        }

        return $this->providers->forSource($source)->fileUrl(RepositoryRef::fromSource($project->team_id, $source), $path, $commitSha, $line);
    }

    /**
     * @param  list<ConfigFileProblemData>  $problems
     * @return list<ConfigFileProblemData>
     */
    public function problems(Project $project, ?string $repository, ?string $commitSha, string $path, array $problems): array
    {
        return array_map(function (ConfigFileProblemData $problem) use ($project, $repository, $commitSha, $path): ConfigFileProblemData {
            $problem->href = $this->url($project, $repository, $commitSha, $path, $problem->line);

            return $problem;
        }, $problems);
    }

    private function source(Project $project, ?string $repository): ?GitSource
    {
        if ($repository === null) {
            return null;
        }

        foreach ($project->services()->where('kind', ServiceKind::App->value)->orderBy('id')->get(['id', 'source']) as $service) {
            /** @var Service $service */
            if ($service->source instanceof GitSource && $service->source->fullName === $repository) {
                return $service->source;
            }
        }

        return null;
    }
}
