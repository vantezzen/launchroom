<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backups;

use App\Actions\Backups\RefreshSnapshots;
use App\Actions\Backups\StartRestore;
use App\Actions\Backups\StartServiceBackup;
use App\Actions\Backups\StartVerification;
use App\Actions\Backups\SwapRestoredService;
use App\Actions\Backups\UpdatePointInTimeRecovery;
use App\Actions\Backups\UpdateVolumeBackup;
use App\Data\Backups\PointInTimeSettingsData;
use App\Data\Backups\RestoreSnapshotData;
use App\Data\Backups\ServiceBackupsData;
use App\Data\Backups\SwapRestoreData;
use App\Data\Backups\VolumeBackupSettingsData;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\VerificationReason;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Models\Backups\BackupRun;
use App\Models\Backups\RestoreRun;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use App\Queries\Backups\ServiceBackupsView;
use App\Support\Refusal;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * JSON endpoints behind the Backups tab of databases and services with volumes
 * (resources/js/features/backups/backups-tab.tsx).
 */
final class ServiceBackupsController extends Controller
{
    /**
     * The tab's data, addressed like the canvas: project / environment / service public ids.
     */
    public function lookup(Project $project, Environment $environment, Service $service, #[CurrentUser] User $user, ServiceBackupsView $view): ServiceBackupsData
    {
        $instance = RouteScope::instance($project, $environment, $service);
        Gate::authorize('viewAny', [BackupRun::class, $instance]);

        return $view->for($instance, $user);
    }

    public function show(ServiceInstance $serviceInstance, #[CurrentUser] User $user, ServiceBackupsView $view): ServiceBackupsData
    {
        Gate::authorize('viewAny', [BackupRun::class, $serviceInstance]);

        return $view->for($serviceInstance, $user);
    }

    public function backup(Request $request, ServiceInstance $serviceInstance, #[CurrentUser] User $user, StartServiceBackup $start, ServiceBackupsView $view): ServiceBackupsData
    {
        Gate::authorize('create', [BackupRun::class, $serviceInstance]);

        Refusal::orThrow($start->handle($serviceInstance, BackupReason::Manual, $user, $this->auditActor($request, $user)));

        return $view->for($serviceInstance, $user);
    }

    public function snapshots(ServiceInstance $serviceInstance, #[CurrentUser] User $user, RefreshSnapshots $refresh, ServiceBackupsView $view): ServiceBackupsData
    {
        Gate::authorize('viewAny', [BackupRun::class, $serviceInstance]);

        $refresh->handle($serviceInstance);

        return $view->for($serviceInstance, $user);
    }

    public function updateVolume(Request $request, ServiceInstance $serviceInstance, Volume $volume, VolumeBackupSettingsData $data, #[CurrentUser] User $user, UpdateVolumeBackup $update, ServiceBackupsView $view): ServiceBackupsData
    {
        Gate::authorize('configure', [BackupRun::class, $serviceInstance]);
        RouteScope::volume($serviceInstance, $volume);

        $update->handle($serviceInstance, $volume, $data->consistency, $this->auditActor($request, $user));

        return $view->for($serviceInstance, $user);
    }

    public function restore(Request $request, ServiceInstance $serviceInstance, RestoreSnapshotData $data, #[CurrentUser] User $user, StartRestore $restore, ServiceBackupsView $view): ServiceBackupsData
    {
        Gate::authorize('create', [RestoreRun::class, $serviceInstance, $data->mode]);

        $restore->handle(
            $serviceInstance,
            $data->snapshot ?? '',
            $data->mode,
            $data->confirmation,
            $user,
            $this->auditActor($request, $user),
            $data->pointInTime,
            $data->point(),
        );

        return $view->for($serviceInstance, $user);
    }

    /**
     * Point-in-time recovery on/off and how far back (owners/admins; docs/14 §10.1).
     */
    public function pointInTime(Request $request, ServiceInstance $serviceInstance, PointInTimeSettingsData $data, #[CurrentUser] User $user, UpdatePointInTimeRecovery $update, ServiceBackupsView $view): ServiceBackupsData
    {
        Gate::authorize('configure', [BackupRun::class, $serviceInstance]);

        $update->handle($serviceInstance, $data->enabled, $data->keepDays, $this->auditActor($request, $user));

        return $view->for($serviceInstance, $user);
    }

    /**
     * Verify the newest backup now (docs/14 §10.4).
     */
    public function verify(Request $request, ServiceInstance $serviceInstance, #[CurrentUser] User $user, StartVerification $start, ServiceBackupsView $view): ServiceBackupsData
    {
        Gate::authorize('create', [BackupRun::class, $serviceInstance]);

        $start->handle($serviceInstance, VerificationReason::Manual, $user, $this->auditActor($request, $user));

        return $view->for($serviceInstance, $user);
    }

    /**
     * Swap a restored copy in for the original (staged; docs/14 §9.16).
     */
    public function swap(Request $request, ServiceInstance $serviceInstance, RestoreRun $restore, SwapRestoreData $data, #[CurrentUser] User $user, SwapRestoredService $swap, ServiceBackupsView $view): ServiceBackupsData
    {
        RouteScope::restore($serviceInstance, $restore);
        Gate::authorize('swap', $restore);

        $swap->handle($restore, $data->confirmation, $user, $this->auditActor($request, $user));

        $serviceInstance->load('environment', 'service');

        return $view->for($serviceInstance->refresh(), $user);
    }
}
