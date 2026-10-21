<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Moves\ServerMove;
use App\Models\Server;
use App\Models\User;
use App\Services\Crypto\Signer;
use App\Services\Instance\DashboardOrigin;
use App\Services\Instance\RecoveryMode;
use App\Services\Moves\MovePlanner;
use App\Services\Moves\MoveToken;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Move launchroom (docs/14 §13.4): opens a control-plane move
 * and issues its one-time token, shown once in the command
 * `install.sh --move <token>` for the new machine. Instance owners only,
 * with a recent password (the controller). Only the token secret's hash is
 * stored; the token stops working when the move ends or after 24 hours
 * without a claim.
 *
 * @return array{move: ControlPlaneMove, token: string}
 */
final readonly class StartControlPlaneMove
{
    public function __construct(
        private Signer $signer,
        private DashboardOrigin $origin,
        private MovePlanner $planner,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @return array{move: ControlPlaneMove, token: string}
     */
    public function handle(User $user, AuditActor $actor): array
    {
        return DB::transaction(function () use ($user, $actor): array {
            if (ControlPlaneMove::open() instanceof ControlPlaneMove) {
                throw DomainRuleViolation::conflict('A move of launchroom is already open. Continue it, or cancel it first.');
            }

            if (RecoveryMode::active()) {
                throw DomainRuleViolation::conflict('Finish recovery mode first.');
            }

            $source = Server::query()->where('is_control_plane', true)->where('status', '!=', ServerStatus::Revoked->value)->orderByDesc('id')->first();

            if (! $source instanceof Server || $source->status !== ServerStatus::Online) {
                throw DomainRuleViolation::conflict('The dashboard’s own server must be online to move.');
            }

            if ($this->planner->openMove($source) instanceof ServerMove) {
                throw DomainRuleViolation::conflict("{$source->name} is already part of a server move.");
            }

            $move = new ControlPlaneMove(['source_server_id' => $source->id, 'created_by' => $user->id]);
            $move->public_id = PublicId::generate(ControlPlaneMove::publicIdPrefix());
            $issued = MoveToken::issue($this->origin->canonical(), $move->public_id, $this->signer->publicKey());
            $move->token_hash = $issued['hash'];
            $move->token_expires_at = now()->addDay();
            $move->status = ControlPlaneMoveStatus::WaitingForServer;
            $move->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ControlPlaneMoveStarted,
                actor: $actor,
                teamId: $source->team_id,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: ['move' => $move->public_id, 'server' => $source->public_id, 'expiresAt' => $move->token_expires_at->toIso8601String()],
            ));

            return ['move' => $move, 'token' => $issued['token']];
        });
    }
}
