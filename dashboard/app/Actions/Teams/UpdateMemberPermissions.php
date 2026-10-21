<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Sessions\EndUserSessions;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Exceptions\DomainRuleViolation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Grants or revokes a developer's Console and Data access (docs/09 R33).
 * Only grantable permissions, only to roles that accept them, and only ones
 * the actor holds. Revoking ends the member's open sessions right away.
 */
final readonly class UpdateMemberPermissions
{
    public function __construct(
        private RecordAuditEvent $audit,
        private EndUserSessions $endSessions,
    ) {}

    /**
     * @param  list<Permission>  $permissions  the complete new set of grants
     */
    public function handle(User $actor, Membership $membership, array $permissions, AuditActor $auditActor): void
    {
        $revoked = DB::transaction(function () use ($actor, $membership, $permissions, $auditActor): bool {
            $membership = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $previous = array_map(static fn (Permission $permission): string => $permission->value, $membership->grantedPermissions());
            $next = [];

            foreach ($permissions as $permission) {
                if (! $membership->role->acceptsGrant($permission)) {
                    throw DomainRuleViolation::on('permissions', 'Only developers can be given this access; owners and admins have it already.');
                }

                $next[$permission->value] = $permission->value;
            }

            sort($next);

            foreach (array_merge(array_diff($next, $previous), array_diff($previous, $next)) as $changed) {
                if (! $actor->hasTeamPermission($membership->team_id, Permission::from($changed))) {
                    throw DomainRuleViolation::forbidden('You can only grant access you have yourself.');
                }
            }

            if ($next === $previous) {
                return false;
            }

            $membership->permissions = $next;
            $membership->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::MemberPermissionsChanged,
                actor: $auditActor,
                teamId: $membership->team_id,
                subjectType: 'membership',
                subjectId: $membership->public_id,
                meta: ['from' => $previous, 'to' => $next],
            ));

            return array_diff($previous, $next) !== [];
        });

        if ($revoked) {
            $this->endSessions->handle($membership->team_id, $membership->user_id);
        }
    }
}
