<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\DestinationStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\InstanceSettings;
use App\Services\Backups\ControlPlaneKeyBundle;
use App\Services\Backups\DestinationSecrets;
use App\Services\Backups\EndpointGuard;
use App\Services\Backups\Process\PostgresClient;
use App\Services\Backups\Process\ProcessFailed;
use App\Services\Backups\Process\ResticCli;
use App\Services\Backups\Process\ResticTarget;
use App\Services\Backups\RecoveryKey;
use App\Services\Backups\SnapshotTags;
use RuntimeException;
use Throwable;

/**
 * Takes the control-plane backup (docs/14 §2 "Control plane"), inside the
 * dashboard container (restic + Postgres 18 client tools ship in its image):
 *
 * 1. `pg_dump --format=custom` of the dashboard database, streamed into
 *    `restic backup --stdin` (both exit codes checked; nothing on disk),
 *    tagged `kind:control-plane`, `part:database`, `run:<bkr id>`;
 * 2. the key bundle (APP_KEY, KEK, signing key, registry keys, .env subset)
 *    sealed to the recovery key, tagged `part:keys` with the same run tag,
 *    bound to the run and the database snapshot id and MAC'd with the
 *    passphrase-derived key (RecoveryKey format 2).
 *
 * `launchroom:restore` pairs the two parts by the run tag.
 */
final readonly class RunControlPlaneBackup
{
    public const string DATABASE_FILE = 'control-plane.pgdump';

    public const string KEYS_FILE = 'control-plane.keys.json';

    public function __construct(
        private DestinationSecrets $secrets,
        private EndpointGuard $guard,
        private ResticCli $restic,
    ) {}

    public function handle(BackupRun $run): BackupRun
    {
        $run = BackupRun::query()->whereKey($run->id)->with('destination')->firstOrFail();

        if ($run->status !== BackupRunStatus::Pending) {
            return $run;
        }

        $run->transitionTo(BackupRunStatus::Running);

        try {
            $outcome = $this->backup($run, $run->destination);
            $run->forceFill(['snapshot_id' => $outcome['snapshot'], 'size_bytes' => $outcome['size'], 'added_bytes' => $outcome['added']]);
            $run->transitionTo(BackupRunStatus::Succeeded);
            $run->destination->markChecked(DestinationStatus::Ok);
        } catch (Throwable $e) {
            $message = $e instanceof ProcessFailed || $e instanceof DomainRuleViolation || $e instanceof RuntimeException
                ? $e->getMessage()
                : 'The control-plane backup failed unexpectedly. See the dashboard logs.';
            $run->transitionTo(BackupRunStatus::Failed, $message);

            if (! $e instanceof ProcessFailed && ! $e instanceof RuntimeException) {
                report($e);
            }
        }

        return $run;
    }

    /**
     * @return array{snapshot: string, size: int, added: int}
     */
    private function backup(BackupRun $run, BackupDestination $destination): array
    {
        $settings = InstanceSettings::current();

        if ($settings->recovery_public_key === null || $settings->recovery_kdf === null) {
            throw new RuntimeException('No recovery passphrase is set, so the keys can’t be backed up.');
        }

        if (! $this->restic->available()) {
            throw ProcessFailed::message('restic is not installed in this dashboard image.');
        }

        $this->guard->check($destination->endpoint, $destination->allow_private_network);
        $target = ResticTarget::for($destination, $this->secrets->reveal($destination));
        $this->restic->openOrInit($target);

        $connection = config()->string('database.default');
        $postgres = PostgresClient::fromConnection($connection);
        $database = config()->string("database.connections.{$connection}.database");

        $dump = $this->restic->backupFromProcess(
            $target,
            $postgres->dump($database),
            self::DATABASE_FILE,
            SnapshotTags::forControlPlane($run->public_id, 'database', $run->reason),
            config()->integer('launchroom.backups.task_timeout_seconds'),
        );

        // Bound to this run and its database snapshot, authenticated with
        // the passphrase-derived key (format 2, review L5); a passphrase set
        // before that existed yields format 1 until it is set again.
        $macKey = $settings->recovery_mac_key === null ? null : base64_decode($settings->recovery_mac_key, true);
        $bundle = RecoveryKey::seal(
            ControlPlaneKeyBundle::collect(),
            $settings->recovery_public_key,
            RecoveryKey::kdf($settings->recovery_kdf),
            ['run' => $run->public_id, 'databaseSnapshot' => $dump->snapshotId],
            $macKey === false ? null : $macKey,
        );
        $keys = $this->restic->backupBytes($target, $bundle, self::KEYS_FILE, SnapshotTags::forControlPlane($run->public_id, 'keys', $run->reason));

        return ['snapshot' => $dump->snapshotId, 'size' => $dump->sizeBytes + $keys->sizeBytes, 'added' => $dump->addedBytes + $keys->addedBytes];
    }
}
