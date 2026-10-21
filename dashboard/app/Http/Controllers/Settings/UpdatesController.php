<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Updates\StartControlPlaneUpdate;
use App\Actions\Updates\StartFleetUpdate;
use App\Actions\Updates\UpdateUpdatePreferences;
use App\Data\Updates\ControlPlaneUpdateData;
use App\Data\Updates\FleetServerData;
use App\Data\Updates\FleetUpdateData;
use App\Data\Updates\ReleaseData;
use App\Data\Updates\StartControlPlaneUpdateInput;
use App\Data\Updates\UpdatePreferencesData;
use App\Data\Updates\UpdatePreferencesInput;
use App\Enums\ServerStatus;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Http\Controllers\Controller;
use App\Models\Backups\BackupDestination;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Updates\ControlPlaneUpdate;
use App\Models\Updates\FleetUpdate;
use App\Models\User;
use App\Queries\FleetUpdateCandidates;
use App\Services\Updates\AutoUpdateWindow;
use App\Services\Updates\ReleaseChecker;
use App\Support\ImageReference;
use App\Support\Semver;
use App\Support\Updates\ReleaseHighlights;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Updates (docs/11 §4), instance owners only: the installed
 * version, a newer release with its changelog, the control-plane update
 * and the rolling launchroomd update of all servers.
 */
final class UpdatesController extends Controller
{
    public function edit(ReleaseChecker $releases, FleetUpdateCandidates $candidates, AutoUpdateWindow $window): Response
    {
        Gate::authorize('manageInstance');

        $version = config()->string('launchroom.version');
        $with = ['daemonTask', 'backupRun', 'fleetUpdate'];
        $controlPlane = ControlPlaneUpdate::query()->with($with)->latest('id')->first();
        $history = ControlPlaneUpdate::query()->with($with)
            ->whereIn('status', [ControlPlaneUpdateStatus::Succeeded->value, ControlPlaneUpdateStatus::Failed->value, ControlPlaneUpdateStatus::RolledBack->value])
            ->latest('id')->limit(5)->get();
        $fleet = FleetUpdate::query()->latest('id')->first();
        $servers = Server::query()->where('status', '!=', ServerStatus::Revoked->value)->orderByDesc('is_control_plane')->orderBy('name')->get();
        $daemonImage = config('launchroom.daemon.image');

        $running = ($controlPlane !== null && ! $controlPlane->status->isTerminal())
            || ($fleet !== null && ! $fleet->status->isTerminal());

        $check = $releases->cached();
        $destination = BackupDestination::query()->whereNull('team_id')->orderByDesc('is_default')->orderBy('id')->first();
        $settings = InstanceSettings::current();
        [$windowStart, $windowEnd] = $window->hours();
        $available = $check->available;

        return Inertia::render('settings/updates', [
            'currentVersion' => $version,
            'check' => $check,
            // The release's section headings as plain text (docs/design/flows.md §15).
            'highlights' => $check->available instanceof ReleaseData ? ReleaseHighlights::from($check->available->notes) : [],
            'hasBackupDestination' => $destination instanceof BackupDestination,
            'backupDestinationName' => $destination?->name,
            // Servers that the guided flow brings to the new release after the control plane.
            'serversBehindRelease' => $available instanceof ReleaseData
                ? $servers->filter(static fn (Server $server): bool => ! $server->is_control_plane && $server->daemon_version !== null && Semver::newer($available->version, $server->daemon_version))->count()
                : 0,
            'history' => array_values($history->map(static fn (ControlPlaneUpdate $update): ControlPlaneUpdateData => ControlPlaneUpdateData::fromUpdate($update))->all()),
            'preferences' => new UpdatePreferencesData(
                channel: $releases->channel(),
                autoUpdate: $settings->auto_update,
                canAutoUpdate: $destination instanceof BackupDestination,
                windowStart: $windowStart,
                windowEnd: $windowEnd,
                timezone: $window->timezone()->getName(),
            ),
            'controlPlaneUpdate' => $controlPlane === null ? null : ControlPlaneUpdateData::fromUpdate($controlPlane),
            'fleetUpdate' => $fleet === null ? null : FleetUpdateData::fromUpdate($fleet),
            'servers' => array_values($servers->map(static fn (Server $server): FleetServerData => FleetServerData::fromServer($server, $version))->all()),
            'serversToUpdate' => count($candidates->forVersion($version)),
            'fleetUpdatable' => is_string($daemonImage) && ImageReference::isDigestPinned($daemonImage) && ! str_ends_with($version, '-dev'),
            'running' => $running,
        ]);
    }

    public function check(ReleaseChecker $releases): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $result = $releases->check(fresh: true);

        return back()->with(
            $result->error === null ? 'success' : 'error',
            $result->error ?? ($result->available instanceof ReleaseData ? "launchroom {$result->available->version} is available." : 'launchroom is up to date.'),
        );
    }

    public function updateControlPlane(Request $request, #[CurrentUser] User $user, StartControlPlaneUpdateInput $input, StartControlPlaneUpdate $start): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $update = $start->handle($user, $input, $this->auditActor($request, $user));

        return back()->with('success', "Updating the control plane to {$update->to_version}.");
    }

    public function updatePreferences(Request $request, #[CurrentUser] User $user, UpdatePreferencesInput $input, UpdateUpdatePreferences $update): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $settings = $update->handle($user, $input, $this->auditActor($request, $user));

        return back()->with('success', $settings->auto_update ? 'Automatic updates are on.' : 'Update settings saved.');
    }

    public function updateFleet(Request $request, #[CurrentUser] User $user, StartFleetUpdate $start): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $update = $start->handle($user, $this->auditActor($request, $user));

        return back()->with('success', "Updating launchroomd to {$update->to_version}, one server at a time.");
    }
}
