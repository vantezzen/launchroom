<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Instance\EnsureUnclaimedInstanceTeam;
use App\Actions\Instance\RegisterSigningKey;
use App\Actions\Servers\CreateEnrollment;
use App\Actions\Servers\RevokeServer;
use App\Data\Audit\AuditActor;
use App\Data\Servers\CreateEnrollmentData;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\Team;
use App\Models\User;
use App\Services\Crypto\Signer;
use App\Services\Instance\RecoveryMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Enrollment for the control-plane host's own daemon (docs/03 §3: "The
 * control-plane host's own daemon enrolls automatically"). Used by the
 * installer and `make dev-enroll`.
 *
 * Before first-run setup (no user yet) the server goes to an unclaimed
 * instance team that the owner claims at /setup (EnsureUnclaimedInstanceTeam).
 *
 * Idempotent: while the control-plane server is enrolled it only reports it;
 * otherwise it invalidates any earlier unused control-plane enrollment and
 * issues a fresh token (tokens are stored hashed and can't be shown again).
 * Prints JSON: {"serverId", "token", "cpKey", "kid"}.
 *
 * `--recovery` (install.sh --restore): the restored database still knows the
 * dead control-plane server. It is not revoked here but kept for the
 * recovery checklist, which replaces it with this server.
 */
#[Signature('launchroom:servers:bootstrap-local {--team= : Public id of the team (default: the first instance owner\'s current team, or the unclaimed instance team before setup)} {--force : Revoke an enrolled control-plane server and issue a new token} {--recovery : After a control-plane restore: keep the previous control-plane server for the recovery checklist (it is replaced there, docs/14 §5.2) instead of revoking it}')]
#[Description('Create the join token for the control-plane host\'s own launchroomd (roles edge, runtime, build) and print it as JSON')]
final class BootstrapLocalServerCommand extends Command
{
    public function handle(Signer $signer, RegisterSigningKey $registerSigningKey, CreateEnrollment $createEnrollment, RevokeServer $revokeServer, EnsureUnclaimedInstanceTeam $ensureUnclaimedTeam): int
    {
        $team = $this->team($ensureUnclaimedTeam);

        if (! $team instanceof Team) {
            $this->components->error('No team found. Finish /setup first (php artisan launchroom:setup-token), or pass --team.');

            return self::FAILURE;
        }

        $actor = AuditActor::system();
        $registerSigningKey->handle($signer, $actor);

        $existing = Server::query()
            ->where('team_id', $team->id)
            ->where('is_control_plane', true)
            ->where('status', '!=', ServerStatus::Revoked->value)
            ->first();

        if ($existing !== null && $this->option('force') !== true && $this->option('recovery') !== true) {
            return $this->output($existing->public_id, null, $signer);
        }

        if ($this->option('recovery') === true) {
            // Recovery mode (docs/14 §5.2): the checklist replaces the previous
            // control-plane server (roles, labels, restores); it stays until then.
            if ($existing !== null) {
                $existing->forceFill(['is_control_plane' => false])->save();
            }

            RecoveryMode::enter($existing);
        } elseif ($existing !== null) {
            $revokeServer->handle($existing, $actor);
        }

        // Only one open control-plane token at a time.
        ServerEnrollment::query()
            ->where('team_id', $team->id)
            ->where('is_control_plane', true)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        $issued = $createEnrollment->handle(
            $team,
            new CreateEnrollmentData([ServerRole::Edge->value, ServerRole::Runtime->value, ServerRole::Build->value], 'control plane'),
            null,
            $actor,
            controlPlane: true,
        );

        return $this->output(null, $issued->token, $signer);
    }

    private function team(EnsureUnclaimedInstanceTeam $ensureUnclaimedTeam): ?Team
    {
        $teamOption = $this->option('team');

        if (is_string($teamOption) && $teamOption !== '') {
            return Team::query()->where('public_id', $teamOption)->first();
        }

        // Fresh install (docs/11 §2): the installer enrolls this daemon before
        // the owner exists; the server goes to the instance team the owner
        // claims at /setup.
        if (! User::query()->exists()) {
            return $ensureUnclaimedTeam->handle(AuditActor::system());
        }

        $owner = User::query()->where('is_instance_owner', true)->orderBy('id')->first();

        return $owner?->currentTeam()->first();
    }

    private function output(?string $serverId, ?string $token, Signer $signer): int
    {
        $this->line(json_encode([
            'serverId' => $serverId,
            'token' => $token,
            'cpKey' => $signer->publicKey(),
            'kid' => $signer->kid,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
