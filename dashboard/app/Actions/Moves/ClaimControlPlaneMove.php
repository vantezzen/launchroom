<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Servers\CancelEnrollment;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Moves\ClaimMoveData;
use App\Data\Moves\MoveReceiverData;
use App\Enums\AuditAction;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Services\Moves\ConnectedMoveTarget;
use App\Services\Moves\MoveToken;
use App\Support\Crypto\PublicKeyFormat;
use Illuminate\Support\Facades\DB;

/**
 * The new machine claims a move (docs/14 §13.4, `install.sh --move`): it
 * proves the token's secret and sends the X25519 public key everything is
 * later sealed to. Both sides then show the same comparison code, which an
 * instance owner confirms. Claiming again with the same key is a no-op
 * (a re-run installer); another key is refused once claimed. A machine
 * that is already a server of this dashboard names it with proof (D103,
 * {@see ConnectedMoveTarget}); the move then takes that server as its target.
 */
final readonly class ClaimControlPlaneMove
{
    public function __construct(
        private ConnectedMoveTarget $connected,
        private CancelEnrollment $cancelEnrollment,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ControlPlaneMove $move, ClaimMoveData $claim, ?string $ip): ControlPlaneMove
    {
        $publicKey = $claim->publicKey;

        if (PublicKeyFormat::parseX25519($publicKey) === null) {
            throw DomainRuleViolation::on('publicKey', 'Expected an x25519: public key.');
        }

        return DB::transaction(function () use ($move, $claim, $publicKey, $ip): ControlPlaneMove {
            $move = ControlPlaneMove::query()->whereKey($move->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals($move->token_hash, MoveToken::hashSecret($claim->secret))) {
                throw DomainRuleViolation::forbidden('This move token is not valid.');
            }

            if ($move->status === ControlPlaneMoveStatus::Cancelled || $move->status === ControlPlaneMoveStatus::HandedOver) {
                throw DomainRuleViolation::forbidden('This move has ended. Start a new one in Settings → Move launchroom.');
            }

            if ($move->receiver_public_key !== null) {
                if (! hash_equals($move->receiver_public_key, $publicKey)) {
                    throw DomainRuleViolation::conflict('Another machine already claimed this move. Cancel it in Settings → Move launchroom and start again.');
                }

                // A re-run installer of a machine that is already a server
                // here (an older installer, or a move stuck while joining):
                // the move takes that server instead of waiting for its join token.
                // (A join token it already used joins it the usual way, AdvanceControlPlaneMoves.)
                if ($claim->serverId !== null && $move->target_server_id === null && in_array($move->status, [ControlPlaneMoveStatus::Claimed, ControlPlaneMoveStatus::Joining], true) && ! $this->joinTokenUsed($move)) {
                    $this->takeConnected($move, $this->connected->verify($move, $claim->serverId, $claim->serverProof, $publicKey));
                }

                return $move;
            }

            if ($move->status !== ControlPlaneMoveStatus::WaitingForServer || $move->token_expires_at->isPast()) {
                throw DomainRuleViolation::forbidden('This move token has expired. Start a new one in Settings → Move launchroom.');
            }

            // Already a server of this dashboard: the owner sees which one
            // before confirming the code, and approval skips the join token.
            $server = $claim->serverId === null ? null : $this->connected->verify($move, $claim->serverId, $claim->serverProof, $publicKey);
            $move->target_server_id = $server?->id;
            $move->receiver_public_key = $publicKey;
            $move->claim_code = MoveToken::claimCode($move->public_id, $publicKey);
            $receiver = new MoveReceiverData(
                hostname: $claim->hostname,
                arch: $claim->arch,
                version: $claim->version,
                address: $ip,
            );
            $move->receiver = $receiver;
            $move->claimed_at = now();
            $move->receiver_seen_at = now();
            $move->transitionTo(ControlPlaneMoveStatus::Claimed);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ControlPlaneMoveClaimed,
                actor: AuditActor::system(),
                subjectType: 'instance',
                subjectId: 'instance',
                meta: array_filter(['move' => $move->public_id, 'hostname' => $receiver->hostname, 'arch' => $receiver->arch, 'ip' => $ip, 'key' => PublicKeyFormat::fingerprint($publicKey), 'server' => $server?->public_id]),
            ));

            return $move;
        });
    }

    private function joinTokenUsed(ControlPlaneMove $move): bool
    {
        return $move->enrollment_id !== null && ServerEnrollment::query()->whereKey($move->enrollment_id)->whereNotNull('used_at')->exists();
    }

    /**
     * The machine the owner already confirmed proved it is $server: a join
     * token it can't use any more is withdrawn and the move continues with
     * that server.
     */
    private function takeConnected(ControlPlaneMove $move, Server $server): void
    {
        $move->target_server_id = $server->id;

        if ($move->status === ControlPlaneMoveStatus::Joining) {
            $enrollment = $move->enrollment_id === null ? null : ServerEnrollment::query()->find($move->enrollment_id);

            if ($enrollment instanceof ServerEnrollment && $enrollment->used_at === null) {
                $this->cancelEnrollment->handle($enrollment, AuditActor::system());
            }

            $move->sealed_join_token = null;
            $move->transitionTo(ControlPlaneMoveStatus::Joined);
        } else {
            $move->save();
        }

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::ControlPlaneMoveClaimed,
            actor: AuditActor::system(),
            subjectType: 'instance',
            subjectId: 'instance',
            meta: ['move' => $move->public_id, 'server' => $server->public_id, 'connected' => true],
        ));
    }
}
