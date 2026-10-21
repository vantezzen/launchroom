<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Previews\PreviewSettings;
use App\Models\Project;
use App\Services\Previews\PreviewAccess;
use Illuminate\Support\Str;

/**
 * Generates (or rotates) the project's CI bypass secret for protected
 * previews (header `X-Launchroom-Bypass`). Returns the new secret, which is
 * shown once; only a nonce is stored (the secret is a MAC under the project
 * DEK, so rotating the project key also rotates it).
 */
final readonly class RotateBypassSecret
{
    public function __construct(
        private PreviewAccess $access,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Project $project, AuditActor $actor): string
    {
        $settings = PreviewSettings::forProject($project);
        $settings->forceFill(['bypass_nonce' => Str::random(48)])->save();

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::PreviewBypassRotated,
            actor: $actor,
            teamId: $project->team_id,
            subjectType: 'project',
            subjectId: $project->public_id,
        ));

        return (string) $this->access->bypassSecret($project, $settings);
    }
}
