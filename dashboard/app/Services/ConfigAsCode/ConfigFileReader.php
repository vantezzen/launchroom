<?php

declare(strict_types=1);

namespace App\Services\ConfigAsCode;

use App\Data\ConfigAsCode\ConfigFileSettingsData;
use App\Data\Git\RepositoryRef;
use App\Data\Services\GitSource;
use App\Enums\ServiceKind;
use App\Models\Project;
use App\Models\Service;
use App\Services\Git\Exceptions\RepositoryFileUnavailable;
use App\Services\Git\GitProviders;
use App\Support\ConfigAsCode\JsonDocument;

/**
 * Finds and reads a project's launchroom.json (docs/18 §3.1): the configured
 * repository (else the one repository of its app services) and path, at an
 * exact commit, through the git provider contract (D46, `fileAtRef`).
 */
final readonly class ConfigFileReader
{
    public function __construct(
        private GitProviders $providers,
    ) {}

    public function settings(Project $project): ConfigFileSettingsData
    {
        return $project->settings->configFile;
    }

    /**
     * The repository holding the file, or null when the file is off or the
     * project's apps use several repositories and none was picked.
     */
    public function repository(Project $project): ?GitSource
    {
        $settings = $this->settings($project);

        if (! $settings->enabled) {
            return null;
        }

        $sources = [];

        foreach ($project->services()->where('kind', ServiceKind::App->value)->orderBy('id')->get(['id', 'source']) as $service) {
            /** @var Service $service */
            $source = $service->source;

            if ($source instanceof GitSource) {
                $sources[$source->provider.':'.$source->repositoryId.':'.$source->fullName] ??= new GitSource($source->repositoryId, $source->fullName, $source->installationId, '', $source->provider, $source->cloneUrl, $source->branch);
            }
        }

        if ($settings->repositoryId !== null) {
            foreach ($sources as $source) {
                if ($source->repositoryId === $settings->repositoryId) {
                    return $source;
                }
            }

            return null;
        }

        return count($sources) === 1 ? array_values($sources)[0] : null;
    }

    /**
     * Whether a push's changed files include the project's launchroom.json
     * (a change of the file concerns every service, docs/18 §3.4).
     *
     * @param  list<string>|null  $changedFiles
     */
    public function touches(Project $project, ?array $changedFiles): bool
    {
        $settings = $this->settings($project);

        return $changedFiles !== null && $settings->enabled && in_array($settings->path, $changedFiles, true);
    }

    /**
     * Whether a push of $repositoryId (provider + connection) concerns the file.
     */
    public function isConfigRepository(Project $project, GitSource $pushed): bool
    {
        $repository = $this->repository($project);

        return $repository instanceof GitSource
            && $repository->provider === $pushed->provider
            && $repository->repositoryId === $pushed->repositoryId
            && $repository->installationId === $pushed->installationId;
    }

    /**
     * The file at $ref (a commit SHA), null when there is none.
     *
     * @throws RepositoryFileUnavailable
     */
    public function read(Project $project, GitSource $repository, string $ref): ?string
    {
        $content = $this->providers->forSource($repository)->fileAtRef(
            RepositoryRef::fromSource($project->team_id, $repository),
            $this->settings($project)->path,
            $ref,
        );

        if ($content !== null && strlen($content) > JsonDocument::MAX_BYTES) {
            // JsonDocument refuses it with a positioned error; don't keep more.
            return substr($content, 0, JsonDocument::MAX_BYTES + 1);
        }

        return $content;
    }
}
