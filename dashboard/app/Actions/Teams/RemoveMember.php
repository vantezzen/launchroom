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
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes a member, or lets a member leave. The last owner can do neither.
 */
final readonly class RemoveMember
{
    public function __construct(
        private RecordAuditEvent $audit,
        private EndUserSessions $endSessions,
    ) {}

    public function handle(User $actor, Membership $membership, AuditActor $auditActor): void
    {
        DB::transaction(function () use ($actor, $membership, $auditActor): void {
            $membership = Membership::query()->with('user')->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $leaving = $membership->user_id === $actor->id;
            $actorRole = $actor->roleIn($membership->team_id);

            if (! $leaving && (! $actorRole instanceof TeamRole || ! $actorRole->canGrant($membership->role))) {
                throw DomainRuleViolation::forbidden('You can’t remove a member with a role above your own.');
            }

            if ($membership->role === TeamRole::Owner) {
                // Lock the owner rows (FOR UPDATE can't be combined with COUNT).
                $owners = count(Membership::query()
                    ->where('team_id', $membership->team_id)
                    ->where('role', TeamRole::Owner->value)
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all());

                if ($owners <= 1) {
                    throw DomainRuleViolation::on('member', 'A team needs at least one owner. Make someone else an owner first.');
                }
            }

            $membership->delete();

            $user = $membership->user;

            // Their API tokens of this team die with the membership (docs/16 §1.1).
            ApiToken::query()->ownedBy($user)->where('team_id', $membership->team_id)->delete();

            if ($user->current_team_id === $membership->team_id) {
                $next = Membership::query()->where('user_id', $user->id)->orderBy('id')->value('team_id');
                $user->forceFill(['current_team_id' => is_int($next) ? $next : null])->save();
            }

            $actor->forgetTeamRoles();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::MemberRemoved,
                actor: $auditActor,
                teamId: $membership->team_id,
                subjectType: 'membership',
                subjectId: $membership->public_id,
                meta: ['role' => $membership->role->value, 'left' => $leaving],
            ));
        });

        // A removed member keeps no Console or Data session (docs/03 §11.3).
        $this->endSessions->handle($membership->team_id, $membership->user_id);
    }
}
