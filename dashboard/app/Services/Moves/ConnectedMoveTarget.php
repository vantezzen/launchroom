<?php

declare(strict_types=1);

namespace App\Services\Moves;

use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Moves\ServerMove;
use App\Models\Server;
use App\Support\Crypto\PublicKeyFormat;

/**
 * The new machine of a control-plane move that is already a server of this
 * dashboard (D103, docs/14 §13.4): its launchroomd enrolled earlier, for
 * example during a move that was cancelled, which keeps the joined server.
 * It can't enroll again (its identity exists), so the move takes that
 * server as its target instead of issuing a join token.
 *
 * The claim names the server and proves it: an Ed25519 signature by the
 * server's launchroomd identity key (the key every request of it is signed
 * with, docs/03 §3) over the move, the server and the receiver's key
 * ({@see MoveToken::serverProofMessage()}). Only the machine holding that
 * key can sign it, and it is bound to the key the owner confirms with the
 * code, so neither a copied claim nor another machine can borrow it.
 */
final readonly class ConnectedMoveTarget
{
    public function __construct(
        private MovePlanner $planner,
    ) {}

    /**
     * The server the claim names, once its proof checks out and it can be
     * the move's target.
     *
     * @throws DomainRuleViolation
     */
    public function verify(ControlPlaneMove $move, string $serverId, ?string $proof, string $receiverPublicKey): Server
    {
        $source = $move->source;
        $server = $source instanceof Server
            ? Server::query()->where('team_id', $source->team_id)->where('public_id', $serverId)->first()
            : null;

        if (! $server instanceof Server) {
            throw DomainRuleViolation::forbidden('This machine is connected to another launchroom, or to a server this dashboard no longer has. Remove that connection on this machine and run the command again.');
        }

        $signature = $proof === null ? false : base64_decode($proof, true);
        $key = PublicKeyFormat::parseEd25519($server->signing_public_key);

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || $key === null || $key === ''
            || ! sodium_crypto_sign_verify_detached($signature, MoveToken::serverProofMessage($move->public_id, $server->public_id, $receiverPublicKey), $key)) {
            throw DomainRuleViolation::forbidden('This machine couldn’t prove that it is the server it names. Nothing was changed.');
        }

        $refusal = $this->refusal($move, $server);

        if ($refusal !== null) {
            throw DomainRuleViolation::conflict($refusal);
        }

        return $server;
    }

    /**
     * Why $server can't be the target of $move (now), or null: it must be an
     * approved, connected server of the dashboard's team, not the
     * dashboard's own server, and not part of another move.
     */
    public function refusal(ControlPlaneMove $move, Server $server): ?string
    {
        $source = $move->source;

        return match (true) {
            ! $source instanceof Server || $server->team_id !== $source->team_id => 'This machine is connected to another launchroom. Remove that connection on this machine and run the command again.',
            $server->id === $source->id => "This machine runs the dashboard ({$server->name}). Run the move command on the new machine.",
            $server->status === ServerStatus::Revoked || $server->revoked_at !== null || $server->replaced_by_server_id !== null => "{$server->name} was removed from this dashboard. Remove its old connection on this machine and run the command again.",
            $server->status === ServerStatus::PendingApproval => "{$server->name} is waiting for approval. Approve it in Servers, then run the command again.",
            $server->status !== ServerStatus::Online => "{$server->name} isn’t connected right now ({$server->status->value}). Check that launchroom runs on this machine (./launchroom status), then run the command again.",
            $this->planner->openMove($server) instanceof ServerMove => "{$server->name} is part of another server move. Finish or cancel it first.",
            ControlPlaneMove::query()->whereKeyNot($move->id)->where('target_server_id', $server->id)->whereIn('status', ControlPlaneMoveStatus::nonTerminal())->exists() => "{$server->name} is the new server of another move.",
            default => null,
        };
    }
}
