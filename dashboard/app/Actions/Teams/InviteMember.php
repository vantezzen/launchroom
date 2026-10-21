<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Teams\InviteMemberData;
use App\Data\Teams\IssuedInvitation;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Support\Crypto\OpaqueToken;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invites someone by e-mail (docs/07 `team_invitations`). The token is
 * single-use, expiring and bound to the e-mail; the inviter can't grant a
 * role above their own (docs/09 R9).
 */
final readonly class InviteMember
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $inviter, Team $team, InviteMemberData $data, AuditActor $actor): IssuedInvitation
    {
        $role = $inviter->roleIn($team);

        if (! $role instanceof TeamRole || ! $role->canGrant($data->role)) {
            throw DomainRuleViolation::on('role', 'You can’t invite someone with a role above your own.');
        }

        $email = Str::lower(trim($data->email));

        $issued = DB::transaction(function () use ($inviter, $team, $data, $actor, $email): IssuedInvitation {
            $alreadyMember = Membership::query()
                ->where('team_id', $team->id)
                ->whereHas('user', fn (Builder $query) => $query->whereRaw('lower(email) = ?', [$email]))
                ->exists();

            if ($alreadyMember) {
                throw DomainRuleViolation::on('email', 'This person is already a member of the team.');
            }

            // One open invitation per e-mail: a new one replaces the old token.
            $team->invitations()->where('email', $email)->whereNull('accepted_at')->delete();

            $token = OpaqueToken::generate('lr_inv_');

            $invitation = TeamInvitation::query()->create([
                'team_id' => $team->id,
                'email' => $email,
                'role' => $data->role,
                'token_hash' => OpaqueToken::hash($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(config()->integer('launchroom.invitations.ttl_days')),
            ]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::MemberInvited,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'team_invitation',
                subjectId: $invitation->public_id,
                meta: ['email' => $email, 'role' => $data->role->value],
            ));

            return new IssuedInvitation($invitation, $token);
        });

        Notification::route('mail', $email)->notify(new TeamInvitationNotification(
            teamName: $team->name,
            inviterName: $inviter->name,
            token: $issued->token,
        ));

        return $issued;
    }
}
