<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Actions\RemoteAccess\EndRemoteAccess;
use App\Actions\Sessions\EndSession;
use App\Enums\TaskBackedAccessStatus;
use App\Enums\TeamRole;
use App\Events\Teams\MemberAccessChanged;
use App\Exceptions\DomainRuleViolation;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\Sessions\InteractiveSession;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the signed-in user's account (Settings → Profile). The role
 * invariants of RemoveMember hold here too (review L2): the last owner of a
 * team, or the only instance owner, can't delete their account, because the
 * team (or the install) would be left with nobody who can manage servers,
 * members, backups or the recovery kit.
 *
 * Live sessions and temporary accesses are ended before the user row goes:
 * their rows cascade with it, so MemberAccessChanged (dispatched after
 * commit) would find nothing left to end on the server.
 */
final readonly class DeleteAccount
{
    public function __construct(
        private EndSession $endSession,
        private EndRemoteAccess $endAccess,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($user->is_instance_owner) {
                $owners = count(User::query()->where('is_instance_owner', true)->lockForUpdate()->pluck('id')->all());

                if ($owners <= 1) {
                    throw DomainRuleViolation::on('password', 'You are the only instance owner. Make someone else an instance owner before deleting your account.');
                }
            }

            $owned = Membership::query()
                ->where('user_id', $user->id)
                ->where('role', TeamRole::Owner->value)
                ->pluck('team_id')
                ->all();

            foreach ($owned as $teamId) {
                // Lock the owner rows (FOR UPDATE can't be combined with COUNT).
                $owners = count(Membership::query()
                    ->where('team_id', $teamId)
                    ->where('role', TeamRole::Owner->value)
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all());

                if ($owners <= 1) {
                    $name = Team::query()->whereKey($teamId)->value('name');

                    throw DomainRuleViolation::on('password', 'You are the last owner of '.(is_string($name) ? $name : 'a team').'. Make someone else an owner first.');
                }
            }

            foreach (Membership::query()->where('user_id', $user->id)->get(['team_id']) as $membership) {
                event(new MemberAccessChanged($membership->team_id, $user->id));
            }

            InteractiveSession::query()
                ->where('user_id', $user->id)
                ->whereIn('status', TaskBackedAccessStatus::nonTerminal())
                ->get()
                ->each(fn (InteractiveSession $session) => $this->endSession->handle($session, null, 'Your access changed.'));

            RemoteAccess::query()
                ->where('user_id', $user->id)
                ->whereIn('status', TaskBackedAccessStatus::nonTerminal())
                ->get()
                ->each(fn (RemoteAccess $access) => $this->endAccess->handle($access, null));

            ApiToken::query()->ownedBy($user)->delete();
            $user->delete();
        });
    }
}
