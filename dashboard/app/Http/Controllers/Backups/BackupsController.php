<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backups;

use App\Actions\Backups\CopyDestinationForInstance;
use App\Actions\Backups\SetVolumeBackup;
use App\Actions\Backups\StartAllBackups;
use App\Actions\Backups\StartServiceBackup;
use App\Actions\Backups\StartVolumeBackup;
use App\Data\Backups\CopyDestinationData;
use App\Data\Backups\ServiceBackupsData;
use App\Data\Backups\VolumeBackupToggleData;
use App\Enums\Backups\BackupReason;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Http\Routing\RouteScope;
use App\Http\Toast;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Models\Volume;
use App\Queries\Backups\BackupsPage;
use App\Queries\Backups\ServiceBackupsView;
use App\Support\Refusal;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Backups page (docs/14 §3, docs/design/flows.md §14) and its actions:
 * back up one row or everything now, turn a volume's backups on or off, and
 * give launchroom itself its own copy of a team storage. Settings → Backups
 * redirects here.
 */
final class BackupsController extends Controller
{
    public function index(#[CurrentUser] User $user, #[CurrentTeam] Team $team, BackupsPage $page): Response
    {
        Gate::authorize('viewAny', [BackupDestination::class, $team]);

        return Inertia::render('backups/index', ['page' => $page->for($team, $user)]);
    }

    public function everything(Request $request, #[CurrentUser] User $user, #[CurrentTeam] Team $team, StartAllBackups $start): RedirectResponse
    {
        Gate::authorize('createAny', [BackupRun::class, $team]);

        $batch = $start->handle($team, $user, $this->auditActor($request, $user));

        if ($batch->started > 0) {
            Toast::success($batch->started === 1 ? 'Backing up 1 item.' : "Backing up {$batch->started} items.");
        } elseif ($batch->alreadyRunning > 0) {
            Toast::info('Everything is already backing up.');
        } else {
            Toast::warning('Nothing could start. The reasons are on the page.');
        }

        return back();
    }

    /**
     * A database, or every backed-up volume of a service.
     */
    public function service(Request $request, ServiceInstance $serviceInstance, #[CurrentUser] User $user, StartServiceBackup $start): RedirectResponse
    {
        Gate::authorize('create', [BackupRun::class, $serviceInstance]);

        $runs = Refusal::orThrow($start->handle($serviceInstance, BackupReason::Manual, $user, $this->auditActor($request, $user)));
        $this->started($runs, $serviceInstance->service->name);

        return back();
    }

    public function volume(Request $request, ServiceInstance $serviceInstance, Volume $volume, #[CurrentUser] User $user, StartVolumeBackup $start): RedirectResponse
    {
        Gate::authorize('create', [BackupRun::class, $serviceInstance]);
        RouteScope::volume($serviceInstance, $volume);

        $run = $start->handle($serviceInstance, $volume, BackupReason::Manual, $user, $this->auditActor($request, $user));
        $this->started([$run], $volume->mount_path);

        return back();
    }

    /**
     * "Back up this volume". JSON callers (the Volumes tab) get the service's Backups tab data back.
     */
    public function volumeBackup(Request $request, ServiceInstance $serviceInstance, Volume $volume, VolumeBackupToggleData $data, #[CurrentUser] User $user, SetVolumeBackup $set, ServiceBackupsView $view): RedirectResponse|ServiceBackupsData
    {
        Gate::authorize('configure', [BackupRun::class, $serviceInstance]);
        RouteScope::volume($serviceInstance, $volume);

        $set->handle($serviceInstance, $volume, $data->enabled, $user, $this->auditActor($request, $user));

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return $view->for($serviceInstance->refresh(), $user);
        }

        Toast::success($data->enabled
            ? "{$volume->mount_path} is backed up again from the next scheduled backup."
            : "{$volume->mount_path} isn’t backed up any more. Existing backups stay until they expire.");

        return back();
    }

    /**
     * "Also protect launchroom itself": launchroom's own storage as a copy of a team storage.
     */
    public function launchroomStorage(Request $request, CopyDestinationData $data, #[CurrentUser] User $user, #[CurrentTeam] Team $team, CopyDestinationForInstance $copy): RedirectResponse
    {
        Gate::authorize('manageInstance', BackupDestination::class);
        $from = BackupDestination::query()->where('team_id', $team->id)->where('public_id', $data->from)->firstOrFail();
        Gate::authorize('view', $from);

        $copy->handle($from, $user, $this->auditActor($request, $user), $data->repositoryPassword);

        Toast::success('launchroom itself now backs up to '.$from->name.', in the folder launchroom. Testing the connection…');

        return back();
    }

    /**
     * @param  list<BackupRun>  $runs
     */
    private function started(array $runs, string $name): void
    {
        $new = array_filter($runs, static fn (BackupRun $run): bool => $run->wasRecentlyCreated);

        if ($runs === []) {
            Toast::info("Backups of {$name} are turned off for every volume.");
        } elseif ($new === []) {
            Toast::info("A backup of {$name} is already running.");
        } else {
            Toast::success("Backup of {$name} started.");
        }
    }
}
