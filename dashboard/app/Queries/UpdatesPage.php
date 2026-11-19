<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Updates\ControlPlaneUpdateData;
use App\Data\Updates\FleetServerData;
use App\Data\Updates\FleetUpdateData;
use App\Data\Updates\PreUpgradeBackupData;
use App\Data\Updates\ReleaseData;
use App\Data\Updates\UpdatePreferencesData;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Updates\ControlPlaneUpdate;
use App\Models\Updates\FleetUpdate;
use App\Services\Updates\AutoUpdateWindow;
use App\Services\Updates\ReleaseChecker;
use App\Support\Images\ImageReference;
use App\Support\Semver;
use App\Support\Updates\ReleaseHighlights;

/**
 * Settings → Updates (docs/11 §4): the installed version, a newer release
 * with its changelog, the control-plane update and the rolling launchroomd
 * update of all servers.
 */
final readonly class UpdatesPage
{
    public function __construct(
        private ReleaseChecker $releases,
        private FleetUpdateCandidates $candidates,
        private AutoUpdateWindow $window,
        private PreUpgradeBackups $backups,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $version = config()->string('launchroom.version');
        $with = ['daemonTask', 'backupRun', 'fleetUpdate'];
        $controlPlane = ControlPlaneUpdate::query()->with($with)->latest('id')->first();
        $history = ControlPlaneUpdate::query()->with($with)
            ->whereIn('status', ControlPlaneUpdateStatus::terminal())
            ->latest('id')->limit(5)->get();
        $fleet = FleetUpdate::query()->latest('id')->first();
        $servers = Server::query()->notRevoked()->orderByDesc('is_control_plane')->orderBy('name')->get();
        $daemonImage = config('launchroom.daemon.image');

        $running = ($controlPlane !== null && ! $controlPlane->status->isTerminal())
            || ($fleet !== null && ! $fleet->status->isTerminal());

        $check = $this->releases->cached();
        $destination = BackupDestination::forInstance();
        $settings = InstanceSettings::current();
        [$windowStart, $windowEnd] = $this->window->hours();
        $available = $check->available;
        $latestBackup = $destination instanceof BackupDestination ? $this->backups->latest($destination) : null;
        $runningBackup = $destination instanceof BackupDestination ? $this->backups->running($destination) : null;

        return [
            'currentVersion' => $version,
            'check' => $check,
            // The release's section headings as plain text (docs/design/flows.md §15).
            'highlights' => $check->available instanceof ReleaseData ? ReleaseHighlights::from($check->available->notes) : [],
            'hasBackupDestination' => $destination instanceof BackupDestination,
            'backupDestinationName' => $destination?->name,
            // What the update dialog offers instead of a new backup (docs/11 §4.1).
            'latestBackup' => $latestBackup instanceof BackupRun ? PreUpgradeBackupData::fromRun($latestBackup) : null,
            'runningBackup' => $runningBackup instanceof BackupRun ? PreUpgradeBackupData::fromRun($runningBackup) : null,
            // Servers that the guided flow brings to the new release after the control plane.
            'serversBehindRelease' => $available instanceof ReleaseData
                ? $servers->filter(static fn (Server $server): bool => ! $server->is_control_plane && $server->daemon_version !== null && Semver::newer($available->version, $server->daemon_version))->count()
                : 0,
            'history' => array_values($history->map(static fn (ControlPlaneUpdate $update): ControlPlaneUpdateData => ControlPlaneUpdateData::fromUpdate($update))->all()),
            'preferences' => new UpdatePreferencesData(
                channel: $this->releases->channel(),
                autoUpdate: $settings->auto_update,
                canAutoUpdate: $destination instanceof BackupDestination,
                windowStart: $windowStart,
                windowEnd: $windowEnd,
                timezone: $this->window->timezone()->getName(),
            ),
            'controlPlaneUpdate' => $controlPlane === null ? null : ControlPlaneUpdateData::fromUpdate($controlPlane),
            'fleetUpdate' => $fleet === null ? null : FleetUpdateData::fromUpdate($fleet),
            'servers' => array_values($servers->map(static fn (Server $server): FleetServerData => FleetServerData::fromServer($server, $version))->all()),
            'serversToUpdate' => count($this->candidates->forVersion($version)),
            'fleetUpdatable' => is_string($daemonImage) && ImageReference::isDigestPinned($daemonImage) && ! str_ends_with($version, '-dev'),
            'running' => $running,
        ];
    }
}
