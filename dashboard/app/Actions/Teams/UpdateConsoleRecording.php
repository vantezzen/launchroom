<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Turns opt-in console transcripts on or off for a team (docs/03 §11.5).
 * Applies to sessions opened afterwards.
 */
final readonly class UpdateConsoleRecording
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Team $team, bool $enabled, AuditActor $actor): void
    {
        DB::transaction(function () use ($team, $enabled, $actor): void {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();

            if ($team->settings->recordConsoleSessions === $enabled) {
                return;
            }

            $settings = clone $team->settings;
            $settings->recordConsoleSessions = $enabled;
            $team->settings = $settings;
            $team->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ConsoleRecordingChanged,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'team',
                subjectId: $team->public_id,
                meta: ['enabled' => $enabled],
            ));
        });
    }
}
