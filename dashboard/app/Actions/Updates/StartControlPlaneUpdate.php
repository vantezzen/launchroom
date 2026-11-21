<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Updates\ReleaseData;
use App\Data\Updates\StartControlPlaneUpdateInput;
use App\Enums\AuditAction;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Enums\Updates\FleetUpdateStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Updates\RunControlPlaneUpdateJob;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Server;
use App\Models\Updates\ControlPlaneUpdate;
use App\Models\Updates\FleetUpdate;
use App\Models\User;
use App\Queries\PreUpgradeBackups;
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
 * The owner picks the backup (docs/11 §4.1): a new one (the default), an
 * existing control-plane backup (`backupRunId`: succeeded, or running now
 * and waited for; any age, on the current destination), or none
 * (`skipBackup`). The chosen run is recorded as the update's backup run.
 * Automatic updates always take a new one.
 *
 * With `updateServers`, the new dashboard rolls every server to the new
 * version once it is up ({@see AdvanceUpdates}). `automatic` marks the
 * nightly automatic update ({@see RunAutomaticUpdate}), which never runs
 * without an off-site backup.
 *
 * "Try again" (`retryOf`) reuses the release a failed or cancelled update
 * had pinned (no GitHub request), so it works while GitHub can't be reached.
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
        private PreUpgradeBackups $backups,
    ) {}

    public function handle(User $user, StartControlPlaneUpdateInput $input, AuditActor $actor, bool $automatic = false): ControlPlaneUpdate
    {
        $current = config()->string('launchroom.version');

        if (! Semver::newer($input->version, $current)) {
            throw DomainRuleViolation::on('version', "Version {$input->version} is not newer than the installed {$current}.");
        }

        [$version, $daemonImage] = $input->retryOf !== null && ! $automatic
            ? $this->previous($input->retryOf, $input->version)
            : $this->pinned($this->release($input->version));

        $update = DB::transaction(function () use ($user, $input, $version, $daemonImage, $current, $automatic): ControlPlaneUpdate {
            $server = Server::query()
                ->controlPlane()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $server instanceof Server) {
                throw DomainRuleViolation::on('version', 'The control-plane server is not enrolled.');
            }

            if (ControlPlaneUpdate::active() instanceof ControlPlaneUpdate
                || FleetUpdate::query()->where('status', FleetUpdateStatus::Running->value)->exists()) {
                throw DomainRuleViolation::conflict('An update is already running.');
            }

            $destination = BackupDestination::query()->instanceWide()->first();

            if (! $destination instanceof BackupDestination && $automatic) {
                throw DomainRuleViolation::on('skipBackup', 'Automatic updates need a control-plane backup destination.');
            }

            if (! $destination instanceof BackupDestination && ! $input->skipBackup) {
                throw DomainRuleViolation::on('skipBackup', 'launchroom itself isn’t backed up yet. Set it up in Settings → Backups, or confirm updating without an off-site backup.');
            }

            $backup = $destination instanceof BackupDestination && ! $automatic && $input->backupRunId !== null
                ? $this->backups->usable($input->backupRunId, $destination)
                : null;

            if (is_string($backup)) {
                throw DomainRuleViolation::on('backupRunId', $backup);
            }

            try {
                $update = ControlPlaneUpdate::query()->create([
                    'from_version' => $current,
                    'to_version' => $version,
                    'daemon_image' => $daemonImage,
                    'skip_backup' => ! $destination instanceof BackupDestination || (! $automatic && ! $backup instanceof BackupRun && $input->skipBackup),
                    'update_servers' => $input->updateServers,
                    'automatic' => $automatic,
                    'server_id' => $server->id,
                    'actor_id' => $user->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw DomainRuleViolation::conflict('An update is already running.');
            }

            if ($backup instanceof BackupRun) {
                $update->forceFill(['backup_run_id' => $backup->id])->save();
                $update->setRelation('backupRun', $backup);
            }

            return $update;
        });

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::ControlPlaneUpdateStarted,
            actor: $actor,
            subjectType: 'control_plane_update',
            subjectId: $update->public_id,
            meta: ['from' => $update->from_version, 'to' => $update->to_version, 'skipBackup' => $update->skip_backup, 'updateServers' => $update->update_servers, 'automatic' => $automatic, ...$this->backupMeta($update)],
        ));

        dispatch(new RunControlPlaneUpdateJob($update->id));

        return $update;
    }

    /**
     * Which backup the update uses (docs/11 §4.1): `new`, an existing run
     * (`existing`, with its id and when it finished, null while it runs),
     * or `none`.
     *
     * @return array<string, string|null>
     */
    private function backupMeta(ControlPlaneUpdate $update): array
    {
        $run = $update->backup_run_id === null ? null : $update->backupRun;

        if ($run instanceof BackupRun) {
            return ['backup' => 'existing', 'backupRun' => $run->public_id, 'backupFinishedAt' => $run->finished_at?->toIso8601String()];
        }

        return ['backup' => $update->skip_backup ? 'none' : 'new'];
    }

    /**
     * @return array{string, string} the version and its digest-pinned launchroomd image
     */
    private function pinned(ReleaseData $release): array
    {
        if ($release->daemonImage === null) {
            throw DomainRuleViolation::on('version', ($release->cannotInstallReason ?? 'This release does not pin its images by digest.')." Install it with the installer instead: {$release->installerCommand}");
        }

        return [$release->version, $release->daemonImage];
    }

    /**
     * "Try again" (docs/11 §4.3): the release an update that didn't go
     * through had pinned, without asking GitHub again. launchroomd still
     * verifies the signed bundle of that version and checks the image
     * against it.
     *
     * @return array{string, string}
     */
    private function previous(string $publicId, string $version): array
    {
        $previous = ControlPlaneUpdate::query()->where('public_id', $publicId)->first();

        if (! $previous instanceof ControlPlaneUpdate || ! $previous->status->isFailed() && $previous->status !== ControlPlaneUpdateStatus::Cancelled) {
            throw DomainRuleViolation::on('retryOf', 'Only an update that didn’t go through can be tried again.');
        }

        if (Semver::compare($previous->to_version, ltrim($version, 'v')) !== 0) {
            throw DomainRuleViolation::on('version', "That update was to {$previous->to_version}.");
        }

        return [$previous->to_version, $previous->daemon_image];
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
