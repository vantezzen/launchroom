<?php

declare(strict_types=1);

namespace App\Console\Commands\Instance;

use App\Actions\Instance\DashboardDomain\ResetDashboardDomain;
use App\Actions\Servers\RenderNodeState;
use App\Data\Audit\AuditActor;
use App\Models\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * The recovery path when a dashboard domain locks you out (docs/05 §4.1,
 * docs/11 §2.3: `./launchroom dashboard-domain-reset`). The control-plane
 * server's routes are rendered right here, so its Caddy serves APP_URL
 * again even while the queue workers are down.
 */
#[Signature('launchroom:dashboard-domain:reset {--forget-old : Stop answering on every earlier address} {--force : Do not ask for confirmation}')]
#[Description('Make APP_URL (.env) the dashboard address again, undoing Settings → Dashboard domain (audited)')]
final class ResetDashboardDomainCommand extends Command
{
    public function handle(ResetDashboardDomain $reset, RenderNodeState $render): int
    {
        if (! $this->option('force') && ! $this->confirm('Make APP_URL the dashboard address again? Everyone signs in again there.', true)) {
            return self::FAILURE;
        }

        $moved = $reset->handle(AuditActor::system(), forgetOld: (bool) $this->option('forget-old'));

        foreach (Server::query()->where('is_control_plane', true)->whereNull('revoked_at')->get() as $server) {
            try {
                $render->handle($server);
            } catch (Throwable $e) {
                $this->components->warn('Could not render the main server’s routes now ('.$e->getMessage().'); the queue will.');
            }
        }

        $this->components->info("The dashboard is at {$moved['to']} again (was {$moved['from']}).");

        return self::SUCCESS;
    }
}
