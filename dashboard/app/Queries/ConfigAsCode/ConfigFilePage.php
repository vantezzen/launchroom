<?php

declare(strict_types=1);

namespace App\Queries\ConfigAsCode;

use App\Data\ConfigAsCode\ConfigFileEnvironmentData;
use App\Data\ConfigAsCode\ConfigFilePageData;
use App\Data\ConfigAsCode\ConfigRepositoryOptionData;
use App\Data\Services\GitSource;
use App\Enums\EnvironmentKind;
use App\Enums\ServiceKind;
use App\Models\ConfigAsCode\EnvironmentConfigFile;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Services\ConfigAsCode\ConfigFileReader;
use App\Support\ConfigAsCode\ConfigSchema;
use Illuminate\Support\Facades\Gate;

/**
 * Settings → General → Config file (docs/18 §3.1): where launchroom.json is
 * read from and what each persistent environment's last read said.
 */
final readonly class ConfigFilePage
{
    public function __construct(
        private ConfigFileReader $reader,
    ) {}

    public function for(Project $project): ConfigFilePageData
    {
        $repositories = [];

        foreach ($project->services()->where('kind', ServiceKind::App->value)->orderBy('id')->get(['id', 'source']) as $service) {
            /** @var Service $service */
            if ($service->source instanceof GitSource) {
                $repositories[$service->source->repositoryId] ??= new ConfigRepositoryOptionData($service->source->repositoryId, $service->source->fullName);
            }
        }

        $environments = $project->environments()
            ->where('kind', '!=', EnvironmentKind::Preview->value)
            ->orderByRaw("case kind when 'production' then 0 else 1 end")
            ->orderBy('name')
            ->get();
        $records = EnvironmentConfigFile::query()->whereIn('environment_id', $environments->modelKeys())->get()->keyBy('environment_id');
        $rows = [];

        foreach ($environments as $environment) {
            /** @var Environment $environment */
            $record = $records->get($environment->id);
            $managed = $record instanceof EnvironmentConfigFile ? $record->managed->services : [];

            $rows[] = new ConfigFileEnvironmentData(
                id: $environment->public_id,
                name: $environment->name,
                kind: $environment->kind,
                status: $record?->status,
                commitSha: $record?->commit_sha,
                checkedAt: $record?->checked_at->toIso8601String(),
                managedServices: count($managed),
                managedSettings: array_sum(array_map(static fn ($service): int => count($service->fields) + count($service->variables) + count($service->domains), $managed)),
                errors: $record?->problems() ?? [],
                warnings: $record?->problems('warnings') ?? [],
                ignored: $record?->problems('ignored') ?? [],
                fileUrl: $record?->webUrl(),
                downloadUrl: route('projects.config.download', [$project, $environment]),
                canSync: Gate::allows('update', $environment),
            );
        }

        return new ConfigFilePageData(
            settings: $this->reader->settings($project),
            repositories: array_values($repositories),
            repository: $this->reader->repository($project)?->fullName,
            environments: $rows,
            schemaUrl: ConfigSchema::ID,
            canUpdate: Gate::allows('update', $project),
        );
    }
}
