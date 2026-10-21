<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Servers\CreateEnrollment;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Servers\CreateEnrollmentData;
use App\Enums\AuditAction;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Enums\ServerRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Server;
use App\Models\User;
use App\Support\Crypto\PublicKeyFormat;
use Illuminate\Support\Facades\DB;

/**
 * An instance owner confirms that the code in the new machine's terminal
 * matches (docs/14 §13.4). The new machine gets a join token (sealed to its
 * key, so only it can read it) for the roles of the dashboard's server, and
 * its launchroomd enrolls as a regular server of this dashboard; everything
 * then moves onto it like a server move.
 */
final readonly class ApproveControlPlaneMove
{
    public function __construct(
        private CreateEnrollment $enrollments,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ControlPlaneMove $move, string $code, User $user, AuditActor $actor): ControlPlaneMove
    {
        return DB::transaction(function () use ($move, $code, $user, $actor): ControlPlaneMove {
            $move = ControlPlaneMove::query()->whereKey($move->id)->with('source.team')->lockForUpdate()->firstOrFail();

            if ($move->status !== ControlPlaneMoveStatus::Claimed || $move->receiver_public_key === null || $move->claim_code === null) {
                throw DomainRuleViolation::conflict('No new server is waiting for approval.');
            }

            if (! hash_equals($move->claim_code, strtoupper(trim($code)))) {
                throw DomainRuleViolation::on('code', 'That isn’t the code this move shows. If the new server’s terminal shows another code, someone else claimed the move: cancel it.');
            }

            $source = $move->source;

            if (! $source instanceof Server) {
                throw DomainRuleViolation::conflict('The dashboard’s server is gone.');
            }

            $roles = array_values(array_filter(
                array_map(static fn (ServerRole $role): string => $role->value, $source->roles->all()),
                static fn (string $role): bool => in_array($role, ['edge', 'runtime', 'build'], true),
            ));
            $name = $move->receiver['hostname'] ?? null;
            $issued = $this->enrollments->handle(
                $source->team,
                new CreateEnrollmentData($roles === [] ? ['edge', 'runtime', 'build'] : $roles, is_string($name) && preg_match('/^[\pL\pN ._-]{1,64}$/u', $name) === 1 ? $name : 'new server'),
                $user,
                $actor,
            );

            $recipient = PublicKeyFormat::parseX25519($move->receiver_public_key) ?? throw DomainRuleViolation::conflict('The new server’s key is malformed.');
            $move->enrollment_id = $issued->enrollment->id;
            $move->sealed_join_token = base64_encode(sodium_crypto_box_seal($issued->token, $recipient));
            $move->approved_by = $user->id;
            $move->transitionTo(ControlPlaneMoveStatus::Joining);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ControlPlaneMoveApproved,
                actor: $actor,
                teamId: $source->team_id,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: ['move' => $move->public_id, 'enrollment' => $issued->enrollment->public_id, 'roles' => $roles],
            ));

            return $move;
        });
    }
}
