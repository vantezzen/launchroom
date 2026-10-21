<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Previews\PreviewSettingsInput;
use App\Enums\AuditAction;
use App\Enums\EnvironmentKind;
use App\Enums\Permission;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Previews\AdvancePreviewsJob;
use App\Models\Previews\PreviewSettings;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saves a project's preview settings (docs/06 §5). Allowing production
 * databases as clone sources needs `backups.manage` (owner/admin) because
 * it copies production data, possibly personal data, into previews. Changes
 * apply to the next pull request event; a raised limit starts queued previews.
 */
final readonly class UpdatePreviewSettings
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Project $project, PreviewSettingsInput $input, User $user, AuditActor $actor): PreviewSettings
    {
        $settings = DB::transaction(function () use ($project, $input, $user, $actor): PreviewSettings {
            $settings = PreviewSettings::forProject($project);
            $settings = PreviewSettings::query()->whereKey($settings->id)->lockForUpdate()->firstOrFail();

            $baseId = null;

            if ($input->baseEnvironmentId !== null) {
                $baseId = $project->environments()->where('public_id', $input->baseEnvironmentId)->where('kind', '!=', EnvironmentKind::Preview->value)->value('id');

                if (! is_int($baseId)) {
                    throw DomainRuleViolation::on('baseEnvironmentId', 'Pick an environment of this project (not a preview).');
                }
            }

            if ($input->allowProductionClone !== $settings->allow_production_clone && ! $user->hasTeamPermission($project->team_id, Permission::ManageBackups)) {
                throw DomainRuleViolation::on('allowProductionClone', 'Only owners and admins can allow cloning production data into previews.');
            }

            $label = $input->requiredLabel === null ? null : trim($input->requiredLabel);
            $branches = $input->targetBranches === null ? null : trim($input->targetBranches);

            $settings->fill([
                'enabled' => $input->enabled,
                'base_environment_id' => $baseId,
                'target_branches' => $branches === '' ? null : $branches,
                'required_label' => $label === '' ? null : $label,
                'max_concurrent' => $input->maxConcurrent,
                'expiry_days' => $input->expiryDays,
                'fork_policy' => $input->forkPolicy,
                'protection' => $input->protection,
                'allow_production_clone' => $input->allowProductionClone,
            ]);
            $changed = array_keys($settings->getDirty());
            $settings->save();

            if ($changed !== []) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::PreviewSettingsUpdated,
                    actor: $actor,
                    teamId: $project->team_id,
                    subjectType: 'project',
                    subjectId: $project->public_id,
                    meta: ['changed' => $changed],
                ));
            }

            return $settings;
        });

        // Protection on/off changes routes; a raised limit frees slots.
        dispatch(new AdvancePreviewsJob($project->team_id));

        return $settings;
    }
}
