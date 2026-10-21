<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\ProjectIconMode;
use App\Jobs\Projects\FetchProjectIconJob;
use App\Models\Project;
use App\Services\Projects\ProjectIconStore;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Removes the project's icon (docs/08 §13): back to the deployed app's
 * favicon (`automatic`, looked up again right away) or no icon at all
 * (`none`, the monogram).
 */
final readonly class ResetProjectIcon
{
    public function __construct(
        private ProjectIconStore $store,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Project $project, ProjectIconMode $mode, AuditActor $actor): Project
    {
        if ($mode === ProjectIconMode::Uploaded) {
            throw new InvalidArgumentException('Uploading goes through SetProjectIcon.');
        }

        $project = DB::transaction(function () use ($project, $mode, $actor): Project {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $project->forceFill([
                'icon_mode' => $mode,
                'icon_path' => null,
                'icon_type' => null,
                'icon_version' => null,
                'icon_source' => null,
                'icon_checked_at' => null,
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ProjectIconChanged,
                actor: $actor,
                teamId: $project->team_id,
                subjectType: 'project',
                subjectId: $project->public_id,
                meta: ['mode' => $mode->value],
            ));

            if ($mode === ProjectIconMode::Automatic) {
                dispatch(new FetchProjectIconJob($project->id))->afterCommit();
            }

            return $project;
        });

        $this->store->deleteAll($project);

        return $project;
    }
}
