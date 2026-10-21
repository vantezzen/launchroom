<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\Data\Audit\AuditActor;
use App\Data\Updates\ReleaseData;
use App\Data\Updates\StartControlPlaneUpdateInput;
use App\Enums\Updates\FleetUpdateStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\InstanceSettings;
use App\Models\Updates\ControlPlaneUpdate;
use App\Models\Updates\FleetUpdate;
use App\Models\User;
use App\Services\Instance\RecoveryMode;
use App\Services\Updates\AutoUpdateWindow;
use App\Services\Updates\ReleaseChecker;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Automatic updates (docs/11 §4; opt-in, off by default), checked hourly:
 * inside the night window of the control-plane server's time zone (UTC
 * when unknown), a newer release on the chosen channel is installed with
 * exactly the flow of "Update now": pre-upgrade backup, control plane, then
 * every server. It never runs
 *
 * - without a control-plane backup destination (no off-site backup, no
 *   automatic update),
 * - for a release that was tried before (a failed or rolled-back version
 *   waits for a person),
 * - while another update runs, in recovery mode, or when the owner who
 *   turned it on is no longer an instance owner.
 *
 * Returns why it did nothing, or the update it started.
 */
final readonly class RunAutomaticUpdate
{
    public function __construct(
        private ReleaseChecker $releases,
        private StartControlPlaneUpdate $start,
        private AutoUpdateWindow $window,
    ) {}

    public function handle(?CarbonInterface $now = null): ControlPlaneUpdate|string
    {
        $settings = InstanceSettings::current();

        if (! $settings->auto_update) {
            return 'automatic updates are off';
        }

        $owner = $settings->auto_update_by === null ? null : User::query()->whereKey($settings->auto_update_by)->where('is_instance_owner', true)->first();

        if (! $owner instanceof User) {
            return 'the owner who turned automatic updates on is no longer an instance owner';
        }

        if (! $this->window->contains($now ?? now())) {
            return 'outside the update window';
        }

        if (RecoveryMode::active()) {
            return 'recovery mode';
        }

        if (ControlPlaneUpdate::active() instanceof ControlPlaneUpdate || FleetUpdate::query()->where('status', FleetUpdateStatus::Running->value)->exists()) {
            return 'an update is running';
        }

        if (! BackupDestination::query()->whereNull('team_id')->exists()) {
            return 'no control-plane backup destination';
        }

        $release = $this->releases->check()->available;

        if (! $release instanceof ReleaseData || $release->daemonImage === null) {
            return 'no installable release';
        }

        if (ControlPlaneUpdate::query()->where('to_version', $release->version)->exists()) {
            return "{$release->version} was tried before";
        }

        try {
            return $this->start->handle($owner, new StartControlPlaneUpdateInput($release->version, updateServers: true), AuditActor::system(), automatic: true);
        } catch (DomainRuleViolation $e) {
            Log::warning('Automatic update not started.', ['version' => $release->version, 'reason' => $e->getMessage()]);

            return $e->getMessage();
        }
    }
}
