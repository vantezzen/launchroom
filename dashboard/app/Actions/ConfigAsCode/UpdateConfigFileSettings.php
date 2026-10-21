<?php

declare(strict_types=1);

namespace App\Actions\ConfigAsCode;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\ConfigAsCode\ConfigFileSettingsData;
use App\Data\Services\GitSource;
use App\Enums\AuditAction;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Settings → General → Config file (docs/18 §3.1): on/off, path and the
 * repository holding launchroom.json (one of the project's app repositories).
 */
final readonly class UpdateConfigFileSettings
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Project $project, ConfigFileSettingsData $data, AuditActor $actor): Project
    {
        if (array_any(explode('/', $data->path), static fn (string $segment): bool => $segment === '.' || $segment === '..')) {
            throw DomainRuleViolation::on('path', 'Use a path inside the repository, like launchroom.json or deploy/launchroom.json.');
        }

        if ($data->repositoryId !== null) {
            $known = $project->services()->where('kind', ServiceKind::App->value)->get(['id', 'source'])
                ->contains(static fn ($service): bool => $service->source instanceof GitSource && $service->source->repositoryId === $data->repositoryId);

            if (! $known) {
                throw DomainRuleViolation::on('repositoryId', 'Pick a repository one of the project’s apps is built from.');
            }
        }

        return DB::transaction(function () use ($project, $data, $actor): Project {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $settings = $project->settings;
            $settings->configFile = $data;
            $project->forceFill(['settings' => $settings])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ConfigFileSettingsUpdated,
                actor: $actor,
                teamId: $project->team_id,
                subjectType: 'project',
                subjectId: $project->public_id,
                meta: ['enabled' => $data->enabled, 'path' => $data->path, 'repositoryId' => $data->repositoryId],
            ));

            return $project;
        });
    }
}
