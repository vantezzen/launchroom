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
use App\Data\Backups\ServiceBackupsData;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\VerificationReason;
use App\Enums\Backups\VolumeConsistency;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Models\Backups\BackupRun;
use App\Models\Backups\RestoreRun;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Backups\ServiceBackupsView;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * JSON endpoints behind the Backups tab of databases and services with volumes
 * (resources/js/features/backups/backups-tab.tsx). Every route binding is
 * team-scoped (App\Http\Routing\TeamScopedBindings).
 */
final class ServiceBackupsController extends Controller
{
    /**
     * The tab's data, addressed like the canvas: project / environment / service public ids.
     */
    public function lookup(Project $project, Environment $environment, Service $service, #[CurrentUser] User $user, ServiceBackupsView $view): ServiceBackupsData
    {
        abort_unless($environment->project_id === $project->id && $service->project_id === $project->id, 404);

        $instance = $service->instances()->where('environment_id', $environment->id)->firstOrFail();
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

        $start->handle($serviceInstance, BackupReason::Manual, $user, $this->auditActor($request, $user));

        return $view->for($serviceInstance, $user);
    }

    public function snapshots(ServiceInstance $serviceInstance, #[CurrentUser] User $user, RefreshSnapshots $refresh, ServiceBackupsView $view): ServiceBackupsData
    {
        Gate::authorize('viewAny', [BackupRun::class, $serviceInstance]);

        $refresh->handle($serviceInstance);

        return $view->for($serviceInstance, $user);
    }

    /**
     * Per-volume backup settings (consistency).
     */
    public function updateVolume(Request $request, ServiceInstance $serviceInstance, string $volume, #[CurrentUser] User $user, UpdateVolumeBackup $update, ServiceBackupsView $view): ServiceBackupsData
    {
        Gate::authorize('configure', [BackupRun::class, $serviceInstance]);
        $volume = $serviceInstance->volumes()->where('public_id', $volume)->firstOrFail();

        $request->validate(['consistency' => ['required', Rule::enum(VolumeConsistency::class)]]);
        $update->handle($serviceInstance, $volume, $request->enum('consistency', VolumeConsistency::class) ?? VolumeConsistency::Live, $this->auditActor($request, $user));

        return $view->for($serviceInstance, $user);
    }

    /**
     * Restore a snapshot, or (pointInTime: true) a Postgres database to a
     * point in time: `at` (ISO 8601) or, without it, the newest point.
     */
    public function restore(Request $request, ServiceInstance $serviceInstance, #[CurrentUser] User $user, StartRestore $restore, ServiceBackupsView $view): ServiceBackupsData
    {
        $request->validate([
            'pointInTime' => ['sometimes', 'boolean'],
            'snapshot' => ['exclude_if:pointInTime,true', 'required', 'string', 'regex:/^[a-f0-9]{8,64}$/D'],
            'at' => ['nullable', 'date'],
            'mode' => ['required', Rule::enum(RestoreMode::class)],
            'confirmation' => ['nullable', 'string', 'max:63'],
        ]);
        $mode = $request->enum('mode', RestoreMode::class) ?? RestoreMode::NewService;

        Gate::authorize('create', [RestoreRun::class, $serviceInstance, $mode]);

        $restore->handle(
            $serviceInstance,
            $request->string('snapshot')->toString(),
            $mode,
            $request->filled('confirmation') ? $request->string('confirmation')->toString() : null,
            $user,
            $this->auditActor($request, $user),
            $request->boolean('pointInTime'),
            $request->filled('at') ? CarbonImmutable::parse($request->string('at')->toString())->utc() : null,
        );

        return $view->for($serviceInstance, $user);
    }

    /**
     * Point-in-time recovery on/off and how far back (owners/admins; docs/14 §10.1).
     */
    public function pointInTime(Request $request, ServiceInstance $serviceInstance, #[CurrentUser] User $user, UpdatePointInTimeRecovery $update, ServiceBackupsView $view): ServiceBackupsData
    {
        Gate::authorize('configure', [BackupRun::class, $serviceInstance]);

        $request->validate([
            'enabled' => ['required', 'boolean'],
            'keepDays' => ['required', 'integer', 'min:1', 'max:35'],
        ]);
        $update->handle($serviceInstance, $request->boolean('enabled'), $request->integer('keepDays'), $this->auditActor($request, $user));

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
    public function swap(Request $request, ServiceInstance $serviceInstance, string $restore, #[CurrentUser] User $user, SwapRestoredService $swap, ServiceBackupsView $view): ServiceBackupsData
    {
        $run = RestoreRun::query()
            ->where('source_service_instance_id', $serviceInstance->id)
            ->where('public_id', $restore)
            ->firstOrFail();
        Gate::authorize('swap', $run);

        $request->validate(['confirmation' => ['required', 'string', 'max:63']]);
        $swap->handle($run, $request->string('confirmation')->toString(), $user, $this->auditActor($request, $user));

        $serviceInstance->load('environment', 'service');
        broadcast(new CanvasChanged($serviceInstance->environment->public_id))->toOthers();

        return $view->for($serviceInstance->refresh(), $user);
    }
}
