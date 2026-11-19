<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Events\Broadcasts\BackupsChanged;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Backups\TestBackupDestinationJob;
use App\Models\Backups\BackupDestination;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Services\Backups\DestinationHistory;
use App\Services\Backups\DestinationSecrets;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * "Also protect launchroom itself" (docs/14 §3, §9.2): launchroom's own
 * (instance) destination as a copy of a team destination. The endpoint,
 * bucket, region and S3 key are copied server-side into a new row with its
 * own data key, in the folder `<prefix>/launchroom`, with its own restic
 * repository password (it goes into the recovery kit). Team admins editing
 * or removing the team destination never touch it.
 *
 * Instance owners only (the caller authorizes). $repositoryPassword
 * reconnects a launchroom folder that already holds backups.
 */
final readonly class CopyDestinationForInstance
{
    public const string FOLDER = 'launchroom';

    public function __construct(
        private DestinationSecrets $secrets,
        private EnsureBackupSchedules $schedules,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(BackupDestination $from, User $user, AuditActor $actor, #[SensitiveParameter] ?string $repositoryPassword = null): BackupDestination
    {
        if (! $user->is_instance_owner) {
            throw DomainRuleViolation::on('from', 'Only an owner of this launchroom can set up its own backup.');
        }

        if ($from->team_id === null) {
            throw DomainRuleViolation::on('from', 'Choose one of your team’s backup storages.');
        }

        $destination = DB::transaction(function () use ($from, $user, $actor, $repositoryPassword): BackupDestination {
            $from = BackupDestination::query()->whereKey($from->id)->whereNotNull('team_id')->lockForUpdate()->firstOrFail();

            if (BackupDestination::query()->instanceWide()->lockForUpdate()->exists()) {
                throw DomainRuleViolation::conflict('launchroom itself already has backup storage.');
            }

            $prefix = ltrim(trim($from->prefix, '/').'/'.self::FOLDER, '/');

            if (BackupDestination::usingLocation($from->endpoint, $from->bucket, $prefix) instanceof BackupDestination) {
                throw DomainRuleViolation::on('from', BackupDestination::LOCATION_TAKEN);
            }

            $credentials = $this->secrets->reveal($from);

            $destination = new BackupDestination([
                'team_id' => null,
                'name' => $from->name,
                'endpoint' => $from->endpoint,
                'bucket' => $from->bucket,
                'region' => $from->region,
                'prefix' => $prefix,
                'allow_private_network' => $from->allow_private_network,
                'is_default' => false,
                'created_by' => $user->id,
            ]);
            $destination->public_id = PublicId::generate(BackupDestination::publicIdPrefix());
            $destination->copied_from_id = $from->id;

            [$password, $passwordSource] = $this->secrets->adoptRepositoryPassword($destination, $repositoryPassword);
            $this->secrets->seal($destination, $credentials->accessKeyId, $credentials->secretAccessKey, $password);
            unset($password);
            $destination->save();

            // New storage for launchroom itself needs a new recovery kit (docs/14 §4).
            InstanceSettings::lockedForUpdate()->forgetRecoveryKit('storage_changed')->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::BackupDestinationCreated,
                actor: $actor,
                subjectType: 'backup_destination',
                subjectId: $destination->public_id,
                meta: ['scope' => 'instance', 'endpoint' => $destination->endpoint, 'bucket' => $destination->bucket, 'copiedFrom' => $from->public_id, 'repositoryPassword' => $passwordSource],
            ));

            DestinationHistory::reconnect($destination);
            DestinationHistory::adoptSchedules($destination);
            $this->schedules->handle($destination);

            return $destination;
        });

        dispatch(new TestBackupDestinationJob($destination->id));
        BackupsChanged::about(null);

        return $destination;
    }
}
