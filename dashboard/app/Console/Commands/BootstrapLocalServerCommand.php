<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Instance\EnsureUnclaimedInstanceTeam;
use App\Actions\Servers\BootstrapControlPlaneServer;
use App\Console\InstanceOwner;
use App\Data\Audit\AuditActor;
use App\Data\Servers\JoinTokenOutput;
use App\Models\Team;
use App\Models\User;
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
 * Prints {@see JoinTokenOutput}.
 */
#[Signature('launchroom:servers:bootstrap-local {--team= : Public id of the team (default: the first instance owner\'s current team, or the unclaimed instance team before setup)} {--force : Revoke an enrolled control-plane server and issue a new token} {--recovery : After a control-plane restore: keep the previous control-plane server for the recovery checklist (it is replaced there, docs/14 §5.2) instead of revoking it}')]
#[Description('Create the join token for the control-plane host\'s own launchroomd (roles edge, runtime, build) and print it as JSON')]
final class BootstrapLocalServerCommand extends Command
{
    public function handle(BootstrapControlPlaneServer $bootstrap, EnsureUnclaimedInstanceTeam $ensureUnclaimedTeam): int
    {
        $team = $this->team($ensureUnclaimedTeam);

        if (! $team instanceof Team) {
            $this->components->error('No team found. Finish /setup first (php artisan launchroom:setup-token), or pass --team.');

            return self::FAILURE;
        }

        $output = $bootstrap->handle($team, $this->option('force') === true, $this->option('recovery') === true, AuditActor::system());
        $this->line($output->toJson());

        return self::SUCCESS;
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

        return InstanceOwner::team();
    }
}
