<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Backups\BackupDestinationInput;
use App\Enums\AuditAction;
use App\Enums\Backups\DestinationStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Backups\TestBackupDestinationJob;
use App\Models\Backups\BackupDestination;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Services\Backups\DestinationSecrets;
use App\Services\Backups\EndpointGuard;
use Illuminate\Support\Facades\DB;

/**
 * Edits a destination. Blank credentials keep the stored ones; a given
 * repository password replaces the stored one (connecting an existing
 * repository). Moving the control-plane repository invalidates the
 * recovery kit (docs/14 §4).
 */
final readonly class UpdateBackupDestination
{
    public function __construct(
        private EndpointGuard $guard,
        private DestinationSecrets $secrets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(BackupDestination $destination, BackupDestinationInput $input, User $user, AuditActor $actor): BackupDestination
    {
        $endpoint = $this->guard->check($input->endpoint, $input->allowPrivateNetwork);

        // The private-network override is the instance owner's decision
        // (docs/09 R5): others may keep it only on the endpoint it was granted for.
        if ($input->allowPrivateNetwork && ! $user->is_instance_owner && (! $destination->allow_private_network || $destination->endpoint !== $endpoint)) {
            throw DomainRuleViolation::on('allowPrivateNetwork', 'Only an instance owner can allow a destination on a private network (docs/09 R5).');
        }
        $region = $input->region === null || $input->region === '' ? null : strtolower($input->region);
        $prefix = trim($input->prefix, '/');
        $this->guard->checkLocation($input->bucket, $prefix, $region);

        $destination = DB::transaction(function () use ($destination, $input, $actor, $endpoint, $region, $prefix): BackupDestination {
            $destination = BackupDestination::query()->whereKey($destination->id)->lockForUpdate()->firstOrFail();
            $moved = $destination->endpoint !== $endpoint || $destination->bucket !== $input->bucket || $destination->prefix !== $prefix;
            $privateNewlyAllowed = $input->allowPrivateNetwork && ! $destination->allow_private_network;

            $newKeys = $input->accessKeyId !== null && $input->accessKeyId !== '' && $input->secretAccessKey !== null && $input->secretAccessKey !== '';
            $credentialsChanged = $newKeys || $input->repositoryPassword !== null;

            if ($credentialsChanged) {
                $current = $this->secrets->reveal($destination);
                $this->secrets->seal(
                    $destination,
                    $newKeys ? (string) $input->accessKeyId : $current->accessKeyId,
                    $newKeys ? (string) $input->secretAccessKey : $current->secretAccessKey,
                    $input->repositoryPassword ?? $current->repositoryPassword,
                );
            }

            if ($input->makeDefault && $destination->team_id !== null && ! $destination->is_default) {
                BackupDestination::query()->where('team_id', $destination->team_id)->update(['is_default' => false]);
                $destination->is_default = true;
            }

            $destination->forceFill([
                'name' => $input->name,
                'endpoint' => $endpoint,
                'bucket' => $input->bucket,
                'region' => $region,
                'prefix' => $prefix,
                'allow_private_network' => $input->allowPrivateNetwork,
            ]);

            if ($moved || $credentialsChanged) {
                $destination->status = DestinationStatus::Unverified;
            }

            $destination->save();

            if ($destination->team_id === null && ($moved || $input->repositoryPassword !== null)) {
                InstanceSettings::lockedForUpdate()->forceFill(['recovery_kit_confirmed_at' => null, 'recovery_kit_downloaded_at' => null])->save();
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::BackupDestinationUpdated,
                actor: $actor,
                teamId: $destination->team_id,
                subjectType: 'backup_destination',
                subjectId: $destination->public_id,
                meta: ['endpoint' => $endpoint, 'bucket' => $input->bucket, 'moved' => $moved, 'credentialsChanged' => $credentialsChanged],
            ));

            if ($privateNewlyAllowed) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::BackupDestinationPrivateNetworkAllowed,
                    actor: $actor,
                    teamId: $destination->team_id,
                    subjectType: 'backup_destination',
                    subjectId: $destination->public_id,
                    meta: ['endpoint' => $endpoint],
                ));
            }

            return $destination;
        });

        if ($destination->status === DestinationStatus::Unverified) {
            dispatch(new TestBackupDestinationJob($destination->id));
        }

        return $destination;
    }
}
