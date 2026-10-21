<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ControlPlaneMove;
use App\Services\Moves\MoveToken;
use App\Support\Crypto\PublicKeyFormat;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * The new machine claims a move (docs/14 §13.4, `install.sh --move`): it
 * proves the token's secret and sends the X25519 public key everything is
 * later sealed to. Both sides then show the same comparison code, which an
 * instance owner confirms. Claiming again with the same key is a no-op
 * (a re-run installer); another key is refused once claimed.
 */
final readonly class ClaimControlPlaneMove
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array{hostname: string, arch: string, version: string|null}  $host
     */
    public function handle(ControlPlaneMove $move, #[SensitiveParameter] string $secret, string $publicKey, array $host, ?string $ip): ControlPlaneMove
    {
        if (PublicKeyFormat::parseX25519($publicKey) === null) {
            throw DomainRuleViolation::on('publicKey', 'Expected an x25519: public key.');
        }

        return DB::transaction(function () use ($move, $secret, $publicKey, $host, $ip): ControlPlaneMove {
            $move = ControlPlaneMove::query()->whereKey($move->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals($move->token_hash, MoveToken::hashSecret($secret))) {
                throw DomainRuleViolation::forbidden('This move token is not valid.');
            }

            if ($move->status === ControlPlaneMoveStatus::Cancelled || $move->status === ControlPlaneMoveStatus::HandedOver) {
                throw DomainRuleViolation::forbidden('This move has ended. Start a new one in Settings → Move launchroom.');
            }

            if ($move->receiver_public_key !== null) {
                if (! hash_equals($move->receiver_public_key, $publicKey)) {
                    throw DomainRuleViolation::conflict('Another machine already claimed this move. Cancel it in Settings → Move launchroom and start again.');
                }

                return $move;
            }

            if ($move->status !== ControlPlaneMoveStatus::WaitingForServer || $move->token_expires_at->isPast()) {
                throw DomainRuleViolation::forbidden('This move token has expired. Start a new one in Settings → Move launchroom.');
            }

            $move->receiver_public_key = $publicKey;
            $move->claim_code = MoveToken::claimCode($move->public_id, $publicKey);
            $receiver = [
                'hostname' => mb_substr($host['hostname'], 0, 64),
                'arch' => mb_substr($host['arch'], 0, 16),
                'version' => $host['version'] === null ? null : mb_substr($host['version'], 0, 32),
                'address' => $ip,
            ];
            $move->receiver = $receiver;
            $move->claimed_at = now();
            $move->receiver_seen_at = now();
            $move->transitionTo(ControlPlaneMoveStatus::Claimed);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ControlPlaneMoveClaimed,
                actor: AuditActor::system(),
                subjectType: 'instance',
                subjectId: 'instance',
                meta: array_filter(['move' => $move->public_id, 'hostname' => $receiver['hostname'], 'arch' => $receiver['arch'], 'ip' => $ip, 'key' => PublicKeyFormat::fingerprint($publicKey)]),
            ));

            return $move;
        });
    }
}
