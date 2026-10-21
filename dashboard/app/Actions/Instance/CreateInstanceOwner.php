<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Teams\CreateTeam;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Instance\CreateOwnerData;
use App\Enums\ActorType;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\InstanceSettings;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Support\Crypto\OpaqueToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * First-run "create owner" (docs/08 §3.1): consumes the setup token, creates
 * the instance owner and their personal team (or claims the team the
 * installer created for the control-plane server, EnsureUnclaimedInstanceTeam).
 * Works exactly once.
 */
final readonly class CreateInstanceOwner
{
    public function __construct(
        private CreateTeam $createTeam,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(CreateOwnerData $data, AuditActor $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $settings = InstanceSettings::lockedForUpdate();

            if ($settings->setup_completed_at !== null || User::query()->exists()) {
                throw DomainRuleViolation::forbidden('Setup is already complete.');
            }

            if ($settings->setup_token_hash === null
                || $settings->setup_token_expires_at === null
                || ! $settings->setup_token_expires_at->isFuture()
                || ! OpaqueToken::matches($data->token, $settings->setup_token_hash)) {
                throw DomainRuleViolation::on('token', 'This setup token is invalid or has expired. Create a new one with `php artisan launchroom:setup-token`.');
            }

            $user = User::query()->create([
                'name' => $data->name,
                'email' => Str::lower($data->email),
                'password' => $data->password,
            ]);
            $user->forceFill(['email_verified_at' => now(), 'is_instance_owner' => true])->save();

            $userActor = new AuditActor(ActorType::User, $user->id, $actor->ip, $actor->userAgent);
            $teamName = Str::before($data->name, ' ').'’s team';
            // The installer may have created the instance team already (it
            // enrolls the control-plane server before setup): the owner gets it.
            $unclaimed = Team::query()->whereDoesntHave('memberships')->orderBy('id')->lockForUpdate()->first();

            if ($unclaimed instanceof Team) {
                $this->claim($unclaimed, $user, $teamName, $userActor);
            } else {
                $this->createTeam->handle($user, $teamName, true, $userActor);
            }

            $settings->forceFill([
                'setup_token_hash' => null,
                'setup_token_expires_at' => null,
                'setup_completed_at' => now(),
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::InstanceOwnerCreated,
                actor: $userActor,
                subjectType: 'user',
                subjectId: (string) $user->id,
            ));

            return $user->refresh();
        });
    }

    private function claim(Team $team, User $owner, string $name, AuditActor $actor): void
    {
        $team->forceFill(['name' => $name, 'personal' => true])->save();

        Membership::query()->create([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'role' => TeamRole::Owner,
        ]);

        $owner->forceFill(['current_team_id' => $team->id])->save();
        $owner->forgetTeamRoles();

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::InstanceTeamClaimed,
            actor: $actor,
            teamId: $team->id,
            subjectType: 'team',
            subjectId: $team->public_id,
        ));
    }
}
