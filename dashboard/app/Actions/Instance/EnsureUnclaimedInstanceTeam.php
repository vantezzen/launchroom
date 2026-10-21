<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Before first-run setup the installer must enroll the control-plane host's
 * daemon (its Caddy serves the dashboard, docs/11 §2), but servers belong to
 * a team and no user exists yet. This creates the instance's team without
 * members; CreateInstanceOwner gives it to the owner at /setup. Idempotent.
 */
final readonly class EnsureUnclaimedInstanceTeam
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(AuditActor $actor): Team
    {
        return DB::transaction(function () use ($actor): Team {
            // Serializes with CreateInstanceOwner, which locks the same row.
            InstanceSettings::lockedForUpdate();

            if (User::query()->exists()) {
                throw DomainRuleViolation::conflict('Setup is already complete; the instance team belongs to its owner.');
            }

            $existing = Team::query()->whereDoesntHave('memberships')->orderBy('id')->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $team = Team::query()->create(['name' => 'launchroom', 'personal' => true]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::TeamCreated,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'team',
                subjectId: $team->public_id,
                meta: ['personal' => true, 'unclaimed' => true],
            ));

            return $team;
        });
    }
}
