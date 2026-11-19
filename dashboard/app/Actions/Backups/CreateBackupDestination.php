<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Backups\BackupDestinationInput;
use App\Enums\AuditAction;
use App\Events\Broadcasts\BackupsChanged;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Backups\TestBackupDestinationJob;
use App\Models\Backups\BackupDestination;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Services\Backups\DestinationHistory;
use App\Services\Backups\DestinationSecrets;
use App\Services\Backups\EndpointGuard;
use App\Support\PublicId;
use App\Support\S3\S3Location;
use Illuminate\Support\Facades\DB;

/**
 * Adds an S3-compatible destination (docs/14 §3): validated endpoint,
 * envelope-encrypted credentials and a restic repository password: the
 * given one (a repository created elsewhere), else the one kept by removed
 * storage at the same bucket and folder (reconnecting needs only new access
 * keys), else a generated one (DestinationSecrets::adoptRepositoryPassword).
 * $team null creates the instance (control-plane) destination. Default
 * schedules follow immediately, and the destination is tested in the
 * background (which also initialises the repository).
 *
 * - A bucket and folder another current destination uses is refused: one
 *   restic repository can't have two passwords.
 * - Schedules whose storage was removed move to the new storage, and history
 *   recorded at the same bucket and folder before it was removed is
 *   reconnected (DestinationHistory).
 * - `protectLaunchroom` (instance owners, team storage, launchroom has none
 *   yet): launchroom's own storage is created as a copy in the same bucket
 *   (CopyDestinationForInstance).
 */
final readonly class CreateBackupDestination
{
    public function __construct(
        private EndpointGuard $guard,
        private DestinationSecrets $secrets,
        private EnsureBackupSchedules $schedules,
        private RecordAuditEvent $audit,
        private CopyDestinationForInstance $copy,
    ) {}

    public function handle(?Team $team, BackupDestinationInput $input, User $user, AuditActor $actor): BackupDestination
    {
        if ($input->allowPrivateNetwork && ! $user->is_instance_owner) {
            throw DomainRuleViolation::on('allowPrivateNetwork', 'Only an instance owner can allow a destination on a private network (docs/09 R5).');
        }

        $endpoint = $this->guard->check($input->endpoint, $input->allowPrivateNetwork);
        $location = S3Location::parse($endpoint, $input->bucket, $input->region, $input->prefix);
        $region = $location->region;
        $prefix = $location->prefix;

        if ($input->accessKeyId === null || $input->accessKeyId === '' || $input->secretAccessKey === null || $input->secretAccessKey === '') {
            throw DomainRuleViolation::on('accessKeyId', 'Enter the access key and secret of an S3 key that can read and write this bucket.');
        }

        $protectLaunchroom = $input->protectLaunchroom && $team instanceof Team;

        if ($protectLaunchroom && ! $user->is_instance_owner) {
            throw DomainRuleViolation::on('protectLaunchroom', 'Only an owner of this launchroom can protect launchroom itself.');
        }

        $destination = DB::transaction(function () use ($team, $input, $user, $actor, $endpoint, $region, $prefix, $protectLaunchroom): BackupDestination {
            if (! $team instanceof Team && BackupDestination::query()->instanceWide()->lockForUpdate()->exists()) {
                throw DomainRuleViolation::conflict('launchroom itself already has backup storage. Edit it instead.');
            }

            if (BackupDestination::usingLocation($endpoint, $input->bucket, $prefix) instanceof BackupDestination) {
                throw DomainRuleViolation::on('prefix', BackupDestination::LOCATION_TAKEN);
            }

            $isDefault = false;

            if ($team instanceof Team && ($input->makeDefault || ! BackupDestination::query()->where('team_id', $team->id)->exists())) {
                BackupDestination::query()->where('team_id', $team->id)->update(['is_default' => false]);
                $isDefault = true;
            }

            $destination = new BackupDestination([
                'team_id' => $team?->id,
                'name' => $input->name,
                'endpoint' => $endpoint,
                'bucket' => $input->bucket,
                'region' => $region,
                'prefix' => $prefix,
                'allow_private_network' => $input->allowPrivateNetwork,
                'is_default' => $isDefault,
                'created_by' => $user->id,
            ]);
            $destination->public_id = PublicId::generate(BackupDestination::publicIdPrefix());

            [$password, $passwordSource] = $this->secrets->adoptRepositoryPassword($destination, $input->repositoryPassword);
            $this->secrets->seal($destination, (string) $input->accessKeyId, (string) $input->secretAccessKey, $password);
            unset($password);
            $destination->save();

            if (! $team instanceof Team) {
                // A new control-plane destination needs a new recovery kit (docs/14 §4).
                InstanceSettings::lockedForUpdate()->forgetRecoveryKit('storage_changed')->save();
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::BackupDestinationCreated,
                actor: $actor,
                teamId: $team?->id,
                subjectType: 'backup_destination',
                subjectId: $destination->public_id,
                meta: ['scope' => $team instanceof Team ? 'team' : 'instance', 'endpoint' => $endpoint, 'bucket' => $input->bucket, 'repositoryPassword' => $passwordSource],
            ));

            if ($input->allowPrivateNetwork) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::BackupDestinationPrivateNetworkAllowed,
                    actor: $actor,
                    teamId: $team?->id,
                    subjectType: 'backup_destination',
                    subjectId: $destination->public_id,
                    meta: ['endpoint' => $endpoint],
                ));
            }

            DestinationHistory::reconnect($destination);
            DestinationHistory::adoptSchedules($destination);
            $this->schedules->handle($destination);

            if ($protectLaunchroom && ! BackupDestination::query()->instanceWide()->exists()) {
                $this->copy->handle($destination, $user, $actor);
            }

            return $destination;
        });

        dispatch(new TestBackupDestinationJob($destination->id));
        BackupsChanged::about($destination->team_id);

        return $destination;
    }
}
