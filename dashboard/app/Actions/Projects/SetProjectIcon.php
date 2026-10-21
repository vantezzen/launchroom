<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\ProjectIconMode;
use App\Exceptions\DomainRuleViolation;
use App\Models\Project;
use App\Services\Projects\ProjectIconStore;
use App\Support\Images\IconSanitizer;
use App\Support\Images\InvalidImage;
use Illuminate\Support\Facades\DB;

/**
 * An uploaded project icon (docs/08 §13): the same checks as a fetched
 * favicon ({@see IconSanitizer}, docs/09 R32), stored re-encoded under a
 * generated path. Uploading switches the project to its own icon until it
 * is reset to automatic.
 */
final readonly class SetProjectIcon
{
    public function __construct(
        private ProjectIconStore $store,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(Project $project, string $bytes, AuditActor $actor): Project
    {
        try {
            $icon = IconSanitizer::sanitize($bytes);
        } catch (InvalidImage $e) {
            throw DomainRuleViolation::on('icon', $e->getMessage());
        }

        $columns = $this->store->put($project, $icon);

        $project = DB::transaction(function () use ($project, $columns, $icon, $actor): Project {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $project->forceFill([...$columns, 'icon_mode' => ProjectIconMode::Uploaded, 'icon_source' => null])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ProjectIconChanged,
                actor: $actor,
                teamId: $project->team_id,
                subjectType: 'project',
                subjectId: $project->public_id,
                meta: ['mode' => ProjectIconMode::Uploaded->value, 'type' => $icon->extension, 'bytes' => strlen($icon->bytes)],
            ));

            return $project;
        });

        $this->store->prune($project);

        return $project;
    }
}
