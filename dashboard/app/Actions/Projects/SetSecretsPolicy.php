<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Projects\ProjectSettingsData;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Services\Variables\ProductionSecretPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Turns "production secrets must differ from preview" on or off for a project
 * (docs/06 §3.1). Turning it on is refused while secrets already violate it
 * (every offender is listed), so the policy never starts out broken.
 */
final readonly class SetSecretsPolicy
{
    public function __construct(
        private ProductionSecretPolicy $policy,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Project $project, bool $enabled, AuditActor $actor): void
    {
        DB::transaction(function () use ($project, $enabled, $actor): void {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if ($project->settings->productionSecretsMustDiffer === $enabled) {
                return;
            }

            $project->forceFill(['settings' => ProjectSettingsData::from([...$project->settings->toArray(), 'productionSecretsMustDiffer' => $enabled])])->save();

            if ($enabled) {
                $this->policy->assert($project, null, 'productionSecretsMustDiffer');
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ProjectSecretsPolicyChanged,
                actor: $actor,
                teamId: $project->team_id,
                subjectType: 'project',
                subjectId: $project->public_id,
                meta: ['productionSecretsMustDiffer' => $enabled],
            ));
        });
    }
}
