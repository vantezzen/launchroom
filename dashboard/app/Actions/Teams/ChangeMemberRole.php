<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Sessions\EndUserSessions;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Changes a member's role. Never above the actor's own role, never one's own
 * role, and a team always keeps at least one owner (docs/09 R9).
 */
final readonly class ChangeMemberRole
{
    public function __construct(
        private RecordAuditEvent $audit,
        private EndUserSessions $endSessions,
    ) {}

    public function handle(User $actor, Membership $membership, TeamRole $role, AuditActor $auditActor): void
    {
        $changed = DB::transaction(function () use ($actor, $membership, $role, $auditActor): bool {
            $membership = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $actorRole = $actor->roleIn($membership->team_id);

            if ($membership->user_id === $actor->id) {
                throw DomainRuleViolation::on('role', 'You can’t change your own role.');
            }

            if (! $actorRole instanceof TeamRole || ! $actorRole->canGrant($membership->role) || ! $actorRole->canGrant($role)) {
                throw DomainRuleViolation::on('role', 'You can’t assign a role above your own.');
            }

            $previous = $membership->role;

            if ($previous === $role) {
                return false;
            }

            $membership->role = $role;
            $membership->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::MemberRoleChanged,
                actor: $auditActor,
                teamId: $membership->team_id,
                subjectType: 'membership',
                subjectId: $membership->public_id,
                meta: ['from' => $previous->value, 'to' => $role->value],
            ));

            return true;
        });

        // Console and Data sessions are opened under the old role (docs/03 §11.3).
        if ($changed) {
            $this->endSessions->handle($membership->team_id, $membership->user_id);
        }
    }
}
