<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Teams\AcceptInvitationData;
use App\Enums\ActorType;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Membership;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Accepts an invitation, either for the signed-in user (whose e-mail must
 * match) or by creating the invitee's account: registration is invite-only
 * (docs/09 R10). The token is single use.
 */
final readonly class AcceptInvitation
{
    public function __construct(
        private FindInvitation $findInvitation,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(#[SensitiveParameter] string $token, ?User $user, ?AcceptInvitationData $account, AuditActor $actor): User
    {
        return DB::transaction(function () use ($token, $user, $account, $actor): User {
            $invitation = $this->findInvitation->handle($token, lock: true);

            if (! $invitation instanceof TeamInvitation) {
                throw DomainRuleViolation::on('token', 'This invitation is invalid, expired or has already been used.');
            }

            if ($user instanceof User) {
                if (Str::lower($user->email) !== Str::lower($invitation->email)) {
                    throw DomainRuleViolation::on('token', 'This invitation was sent to a different e-mail address.');
                }
            } else {
                if (User::query()->whereRaw('lower(email) = ?', [Str::lower($invitation->email)])->exists()) {
                    throw DomainRuleViolation::on('token', 'An account with this e-mail already exists. Log in to accept the invitation.');
                }

                if (! $account instanceof AcceptInvitationData) {
                    throw DomainRuleViolation::on('name', 'Enter your name and a password to create your account.');
                }

                $user = User::query()->create([
                    'name' => $account->name,
                    'email' => $invitation->email,
                    'password' => $account->password,
                ]);
                // The invitation link proves control of the address.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            if (! $user->belongsToTeam($invitation->team_id)) {
                Membership::query()->create([
                    'team_id' => $invitation->team_id,
                    'user_id' => $user->id,
                    'role' => $invitation->role,
                ]);
            }

            $user->forceFill(['current_team_id' => $invitation->team_id])->save();
            $user->forgetTeamRoles();

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::InvitationAccepted,
                actor: $actor->type === ActorType::User ? $actor : new AuditActor(ActorType::User, $user->id, $actor->ip, $actor->userAgent),
                teamId: $invitation->team_id,
                subjectType: 'team_invitation',
                subjectId: $invitation->public_id,
                meta: ['role' => $invitation->role->value],
            ));

            return $user;
        });
    }
}
