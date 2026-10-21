<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a team with $owner as its owner and makes it their current team if
 * they have none yet, or always with $switchTo ("Create team" in the switcher).
 */
final readonly class CreateTeam
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $owner, string $name, bool $personal, AuditActor $actor, bool $switchTo = false): Team
    {
        return DB::transaction(function () use ($owner, $name, $personal, $actor, $switchTo): Team {
            $team = Team::query()->create(['name' => $name, 'personal' => $personal]);

            Membership::query()->create([
                'team_id' => $team->id,
                'user_id' => $owner->id,
                'role' => TeamRole::Owner,
            ]);

            if ($switchTo || $owner->current_team_id === null) {
                $owner->forceFill(['current_team_id' => $team->id])->save();
            }

            $owner->forgetTeamRoles();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::TeamCreated,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'team',
                subjectId: $team->public_id,
                meta: ['personal' => $personal],
            ));

            return $team;
        });
    }
}
