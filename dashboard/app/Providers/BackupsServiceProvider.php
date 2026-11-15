<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ControlPlaneDumps;
use App\Http\Routing\TeamScopedBindings;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\DeletionRequest;
use App\Models\Backups\RestoreRun;
use App\Services\Backups\ControlPlaneDatabase;
use App\Services\Backups\Process\ResticCli;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for backups (docs/14): route bindings and routes.
 */
final class BackupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResticCli::class, static fn (): ResticCli => ResticCli::fromConfig());
        // The control plane's own database as a stream (docs/14 §13.4).
        $this->app->bind(ControlPlaneDumps::class, ControlPlaneDatabase::class);
    }

    public function boot(): void
    {
        TeamScopedBindings::teamOwned([
            'deletion' => DeletionRequest::class,
            'restore' => RestoreRun::class,
        ]);
        TeamScopedBindings::teamOwnedOrInstance([
            'backupDestination' => BackupDestination::class,
            'backupSchedule' => BackupSchedule::class,
        ]);

        $this->loadRoutesFrom(base_path('routes/backups.php'));
    }
}
