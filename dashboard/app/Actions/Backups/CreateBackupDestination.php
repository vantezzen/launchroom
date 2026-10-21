<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Backups\BackupDestinationInput;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Backups\TestBackupDestinationJob;
use App\Models\Backups\BackupDestination;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Services\Backups\DestinationSecrets;
use App\Services\Backups\EndpointGuard;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;

/**
 * Adds an S3-compatible destination (docs/14 §3): validated endpoint,
 * envelope-encrypted credentials and a generated restic repository password.
 * $team null creates the instance (control-plane) destination. Default
 * schedules follow immediately, and the destination is tested in the
 * background (which also initialises the repository).
 */
final readonly class CreateBackupDestination
{
    public function __construct(
        private EndpointGuard $guard,
        private DestinationSecrets $secrets,
        private EnsureBackupSchedules $schedules,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(?Team $team, BackupDestinationInput $input, User $user, AuditActor $actor): BackupDestination
    {
        if ($input->allowPrivateNetwork && ! $user->is_instance_owner) {
            throw DomainRuleViolation::on('allowPrivateNetwork', 'Only an instance owner can allow a destination on a private network (docs/09 R5).');
        }

        $endpoint = $this->guard->check($input->endpoint, $input->allowPrivateNetwork);
        $region = $input->region === null || $input->region === '' ? null : strtolower($input->region);
        $prefix = trim($input->prefix, '/');
        $this->guard->checkLocation($input->bucket, $prefix, $region);

        if ($input->accessKeyId === null || $input->accessKeyId === '' || $input->secretAccessKey === null || $input->secretAccessKey === '') {
            throw DomainRuleViolation::on('accessKeyId', 'Enter the access key and secret of an S3 key that can read and write this bucket.');
        }

        $destination = DB::transaction(function () use ($team, $input, $user, $actor, $endpoint, $region, $prefix): BackupDestination {
            if (! $team instanceof Team && BackupDestination::query()->whereNull('team_id')->lockForUpdate()->exists()) {
                throw DomainRuleViolation::conflict('The control plane already has a backup destination. Edit it instead.');
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

            $this->secrets->seal(
                $destination,
                (string) $input->accessKeyId,
                (string) $input->secretAccessKey,
                $input->repositoryPassword ?? DestinationSecrets::generateRepositoryPassword(),
            );
            $destination->save();

            if (! $team instanceof Team) {
                // A new control-plane destination needs a new recovery kit (docs/14 §4).
                InstanceSettings::lockedForUpdate()->forceFill(['recovery_kit_confirmed_at' => null, 'recovery_kit_downloaded_at' => null])->save();
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::BackupDestinationCreated,
                actor: $actor,
                teamId: $team?->id,
                subjectType: 'backup_destination',
                subjectId: $destination->public_id,
                meta: ['scope' => $team instanceof Team ? 'team' : 'instance', 'endpoint' => $endpoint, 'bucket' => $input->bucket],
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

            $this->schedules->handle($destination);

            return $destination;
        });

        dispatch(new TestBackupDestinationJob($destination->id));

        return $destination;
    }
}
