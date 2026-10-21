<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Canvas\RenameProjectData;
use App\Enums\AuditAction;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Renames a project. The slug stays: it is part of generated hostnames and
 * host-side names, which must not move under running apps.
 */
final readonly class RenameProject
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Project $project, RenameProjectData $data, AuditActor $actor): Project
    {
        return DB::transaction(function () use ($project, $data, $actor): Project {
            $before = $project->name;
            $project->forceFill(['name' => $data->name])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ProjectRenamed,
                actor: $actor,
                teamId: $project->team_id,
                subjectType: 'project',
                subjectId: $project->public_id,
                meta: ['from' => $before, 'to' => $data->name],
            ));

            return $project;
        });
    }
}
