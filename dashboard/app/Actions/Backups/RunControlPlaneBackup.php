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
use Closure;
use Illuminate\Support\Facades\DB;
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
        // Claimed under a row lock: the update job and the backup's own job
        // may both reach a pending run; exactly one of them takes it.
        [$run, $claimed] = DB::transaction(static function () use ($run): array {
            $locked = BackupRun::query()->whereKey($run->id)->with('destination')->lockForUpdate()->firstOrFail();

            if ($locked->status !== BackupRunStatus::Pending) {
                return [$locked, false];
            }

            $locked->forceFill(['heartbeat_at' => now(), 'progress_bytes' => 0]);
            $locked->transitionTo(BackupRunStatus::Running);

            return [$locked, true];
        });

        if (! $claimed) {
            return $run;
        }

        try {
            $outcome = $this->backup($run, $run->destination);
            $this->alive($run);
            $run->forceFill(['snapshot_id' => $outcome['snapshot'], 'size_bytes' => $outcome['size'], 'added_bytes' => $outcome['added'], 'duration_ms' => $this->elapsed($run)]);
            $run->transitionTo(BackupRunStatus::Succeeded);
            $run->destination->markChecked(DestinationStatus::Ok);
        } catch (Throwable $e) {
            // Given up as abandoned while it still ran (it was silent too
            // long): that verdict stands.
            if ($run->refresh()->status->isTerminal()) {
                return $run;
            }

            $message = $e instanceof ProcessFailed || $e instanceof DomainRuleViolation || $e instanceof RuntimeException
                ? $e->getMessage()
                : 'The control-plane backup failed unexpectedly. See the dashboard logs.';
            $run->forceFill(['duration_ms' => $this->elapsed($run)]);
            $run->transitionTo(BackupRunStatus::Failed, $message);

            // The storage itself refused (credentials, password, bucket, network): say so on its card.
            if ($e instanceof ProcessFailed || $e instanceof DomainRuleViolation) {
                $run->destination->markChecked(DestinationStatus::Failing, $message);
            }

            if (! $e instanceof ProcessFailed && ! $e instanceof RuntimeException) {
                report($e);
            }
        }

        return $run;
    }

    /**
     * The heartbeat (docs/11 §4.3): a run that stops beating is given up as
     * abandoned (its worker died). Throws when that already happened, so a
     * worker that was only slow stops instead of finishing a run that
     * counts as failed.
     */
    private function alive(BackupRun $run, ?int $bytes = null): void
    {
        $beat = ['heartbeat_at' => now()];

        if ($bytes !== null) {
            $beat['progress_bytes'] = $bytes;
        }

        $updated = BackupRun::query()->whereKey($run->id)->where('status', BackupRunStatus::Running->value)->update($beat);

        if ($updated === 0) {
            throw new RuntimeException(BackupRun::ABANDONED);
        }
    }

    /**
     * Beats at most every 10 s while the dump streams and restic reports.
     */
    private function heartbeat(BackupRun $run): Closure
    {
        $last = 0.0;

        return function (int $bytes) use ($run, &$last): void {
            if (microtime(true) - $last < 10) {
                return;
            }

            $last = microtime(true);
            $this->alive($run, $bytes);
        };
    }

    private function elapsed(BackupRun $run): ?int
    {
        return $run->started_at === null ? null : (int) $run->started_at->diffInMilliseconds(now());
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
        $this->alive($run);

        $connection = config()->string('database.default');
        $postgres = PostgresClient::fromConnection($connection);
        $database = config()->string("database.connections.{$connection}.database");

        $dump = $this->restic->backupFromProcess(
            $target,
            $postgres->dump($database),
            self::DATABASE_FILE,
            SnapshotTags::forControlPlane($run->public_id, 'database', $run->reason),
            config()->integer('launchroom.backups.task_timeout_seconds'),
            $this->heartbeat($run),
        );
        $this->alive($run, $dump->sizeBytes);

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
