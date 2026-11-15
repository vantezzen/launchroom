<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Backups\DeletionRequestData;
use App\Data\Canvas\EnvironmentData;
use App\Data\Environments\EnvironmentSettingsData;
use App\Data\Logs\RuntimeLogRetentionData;
use App\Data\Projects\ProjectDeletionData;
use App\Data\Projects\ProjectIconData;
use App\Enums\EnvironmentKind;
use App\Enums\ProjectSettingsSection;
use App\Models\Backups\DeletionRequest;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\ConfigAsCode\ConfigFilePage;
use App\Queries\Previews\PreviewSettingsPage;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Props of project settings (docs/08 §2): what every page shows, plus the
 * open section's data.
 */
final readonly class ProjectSettingsPage
{
    public function __construct(
        private ProjectBackupFootprint $backups,
        private ProjectLogRetention $logRetention,
        private ConfigFilePage $configFile,
        private PreviewSettingsPage $previewSettings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Project $project, ProjectSettingsSection $section, User $user): array
    {
        return [
            'section' => $section,
            'icon' => ProjectIconData::fromProject($project),
            'allEnvironments' => $project->environments()
                ->inDisplayOrder()
                ->get()
                ->map(static fn (Environment $environment): EnvironmentData => EnvironmentData::fromEnvironment($environment))
                ->all(),
            'environmentSettings' => $section === ProjectSettingsSection::Environments ? $this->environmentSettings($project, $user) : [],
            'logRetention' => $section === ProjectSettingsSection::Logs ? $this->logRetention->for($project) : [],
            'logRetentionOptions' => ['days' => RuntimeLogRetentionData::dayOptions(), 'maxMb' => RuntimeLogRetentionData::sizeOptions()],
            'configFile' => $section === ProjectSettingsSection::ConfigFile ? $this->configFile->for($project) : null,
            'secretsPolicy' => [
                'project' => $project->settings->productionSecretsMustDiffer,
                'team' => $project->team->settings->productionSecretsMustDiffer,
            ],
            'previewSettings' => $section === ProjectSettingsSection::Previews ? $this->previewSettings->for($project, $user) : null,
            'canCreateEnvironment' => Gate::forUser($user)->allows('create', [Environment::class, $project]),
            'canDelete' => Gate::forUser($user)->allows('delete', $project),
            'deletion' => $section === ProjectSettingsSection::Danger && Gate::forUser($user)->allows('delete', $project) ? $this->deletion($project, $user) : null,
        ];
    }

    private function deletion(Project $project, User $user): ProjectDeletionData
    {
        $footprint = $this->backups->for($project);

        return new ProjectDeletionData(
            servicesWithBackups: $footprint['servicesWithBackups'],
            inFlight: $footprint['inFlight'],
            services: $project->services()->count(),
            environments: $project->environments()->count(),
            pending: DeletionRequestData::openFor(DeletionRequest::PROJECT, $project->id, $user),
            canSkipFinalBackup: Gate::forUser($user)->allows('skipFinalBackup', [DeletionRequest::class, $project->team_id]),
        );
    }

    /**
     * @return list<EnvironmentSettingsData>
     */
    private function environmentSettings(Project $project, User $user): array
    {
        $environments = $project->environments()
            ->with(['baseEnvironment:id,name', 'lastAppliedRevision:id,number,created_at'])
            ->withCount(['serviceInstances' => static fn (Builder $query) => $query->whereNull('removed_at')])
            ->inDisplayOrder()
            ->get();

        $gate = Gate::forUser($user);
        $canSkip = $gate->allows('skipFinalBackup', [DeletionRequest::class, $project->team_id]);

        return array_values($environments->map(function (Environment $environment) use ($user, $gate, $canSkip): EnvironmentSettingsData {
            $footprint = $environment->kind === EnvironmentKind::Production ? ['servicesWithBackups' => 0, 'inFlight' => false] : $this->backups->forEnvironment($environment);
            $count = $environment->getAttribute('service_instances_count');

            return new EnvironmentSettingsData(
                id: $environment->public_id,
                name: $environment->name,
                slug: $environment->slug,
                kind: $environment->kind,
                triggers: $environment->triggers,
                autoDeployPaused: $environment->auto_deploy_paused,
                baseName: $environment->baseEnvironment?->name,
                services: is_numeric($count) ? (int) $count : 0,
                revision: $environment->lastAppliedRevision?->number,
                lastDeployedAt: $environment->lastAppliedRevision?->created_at->toIso8601String(),
                canUpdate: $gate->allows('update', $environment),
                canDelete: $gate->allows('delete', $environment),
                servicesWithBackups: $footprint['servicesWithBackups'],
                backupInFlight: $footprint['inFlight'],
                pendingDeletion: DeletionRequestData::openFor(DeletionRequest::ENVIRONMENT, $environment->id, $user),
                canSkipFinalBackup: $canSkip,
            );
        })->all());
    }
}
