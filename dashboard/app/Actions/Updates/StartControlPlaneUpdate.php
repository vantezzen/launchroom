<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Updates\ReleaseData;
use App\Data\Updates\StartControlPlaneUpdateInput;
use App\Enums\AuditAction;
use App\Enums\ServerStatus;
use App\Enums\Updates\FleetUpdateStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Updates\RunControlPlaneUpdateJob;
use App\Models\Backups\BackupDestination;
use App\Models\Server;
use App\Models\Updates\ControlPlaneUpdate;
use App\Models\Updates\FleetUpdate;
use App\Models\User;
use App\Services\Updates\ReleaseChecker;
use App\Services\Updates\ReleaseCheckFailed;
use App\Support\Semver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Starts a control-plane update to a release (docs/11 §4). The work runs in
 * {@see RunControlPlaneUpdateJob}: pre-upgrade backup, then a self_update
 * task (scope control_plane) for the control-plane server's launchroomd,
 * which verifies the signed bundle and installs it with rollback.
 *
 * With `updateServers`, the new dashboard rolls every server to the new
 * version once it is up ({@see AdvanceUpdates}). `automatic` marks the
 * nightly automatic update ({@see RunAutomaticUpdate}), which never runs
 * without an off-site backup.
 *
 * Refuses: versions that are not newer, releases without digest-pinned
 * images, a running update (control plane or servers), and, when no
 * control-plane backup destination exists, unless the owner acknowledged
 * updating without an off-site backup.
 */
final readonly class StartControlPlaneUpdate
{
    public function __construct(
        private ReleaseChecker $releases,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $user, StartControlPlaneUpdateInput $input, AuditActor $actor, bool $automatic = false): ControlPlaneUpdate
    {
        $current = config()->string('launchroom.version');

        if (! Semver::newer($input->version, $current)) {
            throw DomainRuleViolation::on('version', "Version {$input->version} is not newer than the installed {$current}.");
        }

        $release = $this->release($input->version);

        if ($release->daemonImage === null) {
            throw DomainRuleViolation::on('version', ($release->cannotInstallReason ?? 'This release does not pin its images by digest.')." Install it with the installer instead: {$release->installerCommand}");
        }

        $update = DB::transaction(function () use ($user, $input, $release, $current, $automatic): ControlPlaneUpdate {
            $server = Server::query()
                ->where('is_control_plane', true)
                ->where('status', '!=', ServerStatus::Revoked->value)
                ->lockForUpdate()
                ->first();

            if (! $server instanceof Server) {
                throw DomainRuleViolation::on('version', 'The control-plane server is not enrolled.');
            }

            if (ControlPlaneUpdate::active() instanceof ControlPlaneUpdate
                || FleetUpdate::query()->where('status', FleetUpdateStatus::Running->value)->exists()) {
                throw DomainRuleViolation::conflict('An update is already running.');
            }

            $hasDestination = BackupDestination::query()->whereNull('team_id')->exists();

            if (! $hasDestination && $automatic) {
                throw DomainRuleViolation::on('skipBackup', 'Automatic updates need a control-plane backup destination.');
            }

            if (! $hasDestination && ! $input->skipBackup) {
                throw DomainRuleViolation::on('skipBackup', 'There is no control-plane backup destination. Add one in Settings → Backups, or confirm updating without an off-site backup.');
            }

            try {
                return ControlPlaneUpdate::query()->create([
                    'from_version' => $current,
                    'to_version' => $release->version,
                    'daemon_image' => (string) $release->daemonImage,
                    'skip_backup' => ! $hasDestination,
                    'update_servers' => $input->updateServers,
                    'automatic' => $automatic,
                    'server_id' => $server->id,
                    'actor_id' => $user->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw DomainRuleViolation::conflict('An update is already running.');
            }
        });

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::ControlPlaneUpdateStarted,
            actor: $actor,
            subjectType: 'control_plane_update',
            subjectId: $update->public_id,
            meta: ['from' => $update->from_version, 'to' => $update->to_version, 'skipBackup' => $update->skip_backup, 'updateServers' => $update->update_servers, 'automatic' => $automatic],
        ));

        dispatch(new RunControlPlaneUpdateJob($update->id));

        return $update;
    }

    private function release(string $version): ReleaseData
    {
        try {
            $release = $this->releases->release($version);
        } catch (ReleaseCheckFailed $e) {
            throw DomainRuleViolation::on('version', 'Could not load the release: '.$e->getMessage());
        }

        return $release ?? throw DomainRuleViolation::on('version', "There is no release v{$version}.");
    }
}
