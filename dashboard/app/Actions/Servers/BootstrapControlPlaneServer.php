<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Instance\RegisterSigningKey;
use App\Data\Audit\AuditActor;
use App\Data\Servers\CreateEnrollmentData;
use App\Data\Servers\JoinTokenOutput;
use App\Enums\ServerRole;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\Team;
use App\Services\Crypto\Signer;
use App\Services\Instance\RecoveryMode;
use Illuminate\Support\Facades\DB;

/**
 * Enrollment for the control-plane host's own daemon (docs/03 §3: "The
 * control-plane host's own daemon enrolls automatically").
 *
 * Idempotent: while the control-plane server is enrolled it only reports it,
 * unless forced; otherwise it invalidates earlier unused control-plane tokens
 * and issues a fresh one (tokens are stored hashed and can't be shown again).
 *
 * With $recovery (after a control-plane restore, docs/14 §5.2) the previous
 * control-plane server is not revoked but kept for the recovery checklist,
 * which replaces it with this server.
 */
final readonly class BootstrapControlPlaneServer
{
    public function __construct(
        private Signer $signer,
        private RegisterSigningKey $registerSigningKey,
        private CreateEnrollment $createEnrollment,
        private RevokeServer $revokeServer,
    ) {}

    public function handle(Team $team, bool $force, bool $recovery, AuditActor $actor): JoinTokenOutput
    {
        $this->registerSigningKey->handle($this->signer, $actor);

        $existing = Server::query()
            ->where('team_id', $team->id)
            ->controlPlane()
            ->latest('id')
            ->first();

        if ($existing !== null && ! $force && ! $recovery) {
            return new JoinTokenOutput($existing->public_id, null, $this->signer->publicKey(), $this->signer->kid);
        }

        if ($recovery) {
            DB::transaction(static function () use ($existing): void {
                $existing?->forceFill(['is_control_plane' => false])->save();
                RecoveryMode::enter($existing);
            });
        } elseif ($existing !== null) {
            $this->revokeServer->handle($existing, $actor);
        }

        ServerEnrollment::query()
            ->where('team_id', $team->id)
            ->where('is_control_plane', true)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        $issued = $this->createEnrollment->handle(
            $team,
            new CreateEnrollmentData([ServerRole::Edge->value, ServerRole::Runtime->value, ServerRole::Build->value], 'control plane'),
            null,
            $actor,
            controlPlane: true,
        );

        return new JoinTokenOutput(null, $issued->token, $this->signer->publicKey(), $this->signer->kid);
    }
}
