<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ControlPlaneDumps;
use App\Http\Routing\TeamScopedBindings;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\DeletionRequest;
use App\Models\Backups\RestoreRun;
use App\Models\User;
use App\Services\Backups\ControlPlaneDatabase;
use App\Services\Backups\Process\ResticCli;
use Illuminate\Database\Eloquent\Builder;
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
        // Current or removed storage (removed storage keeps its repository password).
        TeamScopedBindings::define('anyBackupDestination', BackupDestination::class, static function (Builder $query, User $user): void {
            $query->withTrashed()->where(static function (Builder $query) use ($user): void {
                $query->whereIn('team_id', $user->teamIdsQuery());

                if ($user->is_instance_owner) {
                    $query->orWhereNull('team_id');
                }
            });
        });

        $this->loadRoutesFrom(base_path('routes/backups.php'));
    }
}
