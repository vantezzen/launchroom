<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ControlPlaneDumps;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\BackupVerification;
use App\Models\Backups\DeletionRequest;
use App\Models\Backups\RestoreRun;
use App\Models\Backups\WalArchive;
use App\Models\User;
use App\Policies\Backups\BackupDestinationPolicy;
use App\Policies\Backups\BackupRunPolicy;
use App\Policies\Backups\BackupSchedulePolicy;
use App\Policies\Backups\BackupVerificationPolicy;
use App\Policies\Backups\DeletionRequestPolicy;
use App\Policies\Backups\RestoreRunPolicy;
use App\Policies\Backups\WalArchivePolicy;
use App\Services\Backups\ControlPlaneDatabase;
use App\Services\Backups\Process\ResticCli;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for backups (docs/14): policies, route bindings, routes.
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
        Gate::policy(BackupDestination::class, BackupDestinationPolicy::class);
        Gate::policy(BackupSchedule::class, BackupSchedulePolicy::class);
        Gate::policy(BackupRun::class, BackupRunPolicy::class);
        Gate::policy(RestoreRun::class, RestoreRunPolicy::class);
        Gate::policy(DeletionRequest::class, DeletionRequestPolicy::class);
        Gate::policy(WalArchive::class, WalArchivePolicy::class);
        Gate::policy(BackupVerification::class, BackupVerificationPolicy::class);

        // Team-scoped like App\Http\Routing\TeamScopedBindings, plus the
        // instance-level rows (team_id null) for instance owners only.
        Route::bind('backupDestination', static fn (string $value): Model => self::resolve(BackupDestination::class, $value));
        Route::bind('backupSchedule', static fn (string $value): Model => self::resolve(BackupSchedule::class, $value));

        $this->loadRoutesFrom(base_path('routes/backups.php'));
    }

    /**
     * @param  class-string<BackupDestination|BackupSchedule>  $class
     */
    private static function resolve(string $class, string $value): Model
    {
        $user = Auth::user();

        if (! $user instanceof User || ! PublicId::isValid($value, $class::publicIdPrefix())) {
            throw (new ModelNotFoundException)->setModel($class);
        }

        return $class::query()
            ->where('public_id', $value)
            ->where(static function (Builder $query) use ($user): void {
                $query->whereIn('team_id', $user->teamIdsQuery());

                if ($user->is_instance_owner) {
                    $query->orWhereNull('team_id');
                }
            })
            ->firstOrFail();
    }
}
