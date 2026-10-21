<?php

declare(strict_types=1);

namespace App\Actions\Dockerfiles;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * The team's opt-in to "Generate a Dockerfile" (docs/20 §1). Applies to
 * generations started afterwards.
 */
final readonly class UpdateTeamDockerfileGeneration
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Team $team, bool $enabled, AuditActor $actor): void
    {
        DB::transaction(function () use ($team, $enabled, $actor): void {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();

            if ($team->settings->generateDockerfiles === $enabled) {
                return;
            }

            $settings = clone $team->settings;
            $settings->generateDockerfiles = $enabled;
            $team->settings = $settings;
            $team->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DockerfileGenerationTeamChanged,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'team',
                subjectId: $team->public_id,
                meta: ['enabled' => $enabled],
            ));
        });
    }
}
