<?php

declare(strict_types=1);

namespace App\Queries\Previews;

use App\Data\Canvas\EnvironmentData;
use App\Data\Previews\CloneCandidateData;
use App\Data\Previews\PreviewServiceData;
use App\Data\Previews\PreviewSettingsData;
use App\Data\Services\DatabaseSource;
use App\Enums\EnvironmentKind;
use App\Enums\Permission;
use App\Enums\PreviewStrategy;
use App\Models\Environment;
use App\Models\Previews\PreviewSettings;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Previews\PreviewDatabases;
use App\Services\Previews\PreviewGate;

/**
 * Props of project settings → Previews.
 */
final readonly class PreviewSettingsPage
{
    public function __construct(
        private PreviewGate $gate,
        private PreviewDatabases $databases,
    ) {}

    public function for(Project $project, User $user): PreviewSettingsData
    {
        $settings = PreviewSettings::forProject($project);
        $base = $this->gate->baseEnvironment($project, $settings)
            ?? $project->environments()->where('kind', EnvironmentKind::Production->value)->first();

        $services = [];

        if ($base instanceof Environment) {
            $instances = ServiceInstance::query()
                ->current()
                ->where('environment_id', $base->id)
                ->with(['service', 'previewCloneSource'])
                ->orderBy('id')
                ->get();

            foreach ($instances as $instance) {
                $source = $instance->service->source;

                if (! $instance->service->kind->isStateful()) {
                    continue;
                }

                $services[] = new PreviewServiceData(
                    serviceId: $instance->service->public_id,
                    name: $instance->service->name,
                    engine: $source instanceof DatabaseSource ? $source->engine->value : 'database',
                    strategy: $instance->preview_strategy ?? PreviewStrategy::NewEmpty,
                    cloneSourceId: $instance->previewCloneSource?->public_id,
                    sanitizeCommand: $instance->preview_sanitize_command,
                    candidates: array_map(static fn (array $candidate): CloneCandidateData => new CloneCandidateData(
                        id: $candidate['instance']->public_id,
                        service: $candidate['instance']->service->name,
                        environment: $candidate['instance']->environment->name,
                        production: $candidate['instance']->environment->kind === EnvironmentKind::Production,
                        sizeBytes: $candidate['sizeBytes'],
                        backedUpAt: $candidate['backedUpAt'],
                        allowed: $candidate['allowed'],
                        reason: $candidate['reason'],
                    ), $this->databases->candidates($instance, $settings)),
                );
            }
        }

        return new PreviewSettingsData(
            enabled: $settings->enabled,
            baseEnvironmentId: $settings->baseEnvironment?->public_id,
            effectiveBaseId: $base?->public_id,
            targetBranches: $settings->target_branches,
            requiredLabel: $settings->required_label,
            maxConcurrent: $settings->max_concurrent,
            expiryDays: $settings->expiry_days,
            forkPolicy: $settings->fork_policy,
            protection: $settings->protection,
            allowProductionClone: $settings->allow_production_clone,
            hasBypassSecret: $settings->bypass_nonce !== null,
            bypassHeader: config()->string('launchroom.previews.bypass_header'),
            cloneLimitMb: intdiv($this->databases->limitBytes($project->team_id), 1024 * 1024),
            baseOptions: array_values($project->environments()
                ->where('kind', '!=', EnvironmentKind::Preview->value)
                ->orderByRaw("case kind when 'production' then 0 else 1 end")
                ->orderBy('name')
                ->get()
                ->map(static fn (Environment $environment): EnvironmentData => EnvironmentData::fromEnvironment($environment))
                ->all()),
            services: $services,
            canManage: $user->hasTeamPermission($project->team_id, Permission::ManageProjects),
            canAllowProductionClone: $user->hasTeamPermission($project->team_id, Permission::ManageBackups),
        );
    }
}
