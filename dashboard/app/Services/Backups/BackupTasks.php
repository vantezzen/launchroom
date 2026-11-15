<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Data\Backups\RetentionData;
use App\Data\Protocol\V1\Resources;
use App\Data\Protocol\V1\Tasks\BackupDestination as DestinationSpec;
use App\Data\Protocol\V1\Tasks\BackupMaintenanceSpec;
use App\Data\Protocol\V1\Tasks\BackupSnapshotsSpec;
use App\Data\Protocol\V1\Tasks\BackupSpec;
use App\Data\Protocol\V1\Tasks\BackupTarget;
use App\Data\Protocol\V1\Tasks\RestoreRuntime;
use App\Data\Protocol\V1\Tasks\RestoreSpec;
use App\Data\Protocol\V1\Tasks\Retention;
use App\Data\Protocol\V1\Tasks\TaskSpec;
use App\Data\Protocol\V1\Tasks\VerifyRestoreSpec;
use App\Data\Tasks\TaskSeal;
use App\Models\Backups\BackupDestination;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Services\Tasks\DaemonTasks;
use InvalidArgumentException;

/**
 * Creates backup daemon tasks (docs/14 §7), pinned to a server: the one
 * that runs the database or holds the volume, or for repository-only tasks
 * (snapshots, maintenance) one the caller chose. The destination's
 * credentials are sealed to that server when it leases the task (docs/03
 * §7), like every other task secret.
 */
final readonly class BackupTasks
{
    public function __construct(
        private DaemonTasks $tasks,
    ) {}

    /**
     * @param  array{engine: string, workload: string}  $target
     * @param  list<string>  $tags
     */
    public function backup(BackupDestination $destination, Server $server, int $teamId, array $target, array $tags): DaemonTask
    {
        return $this->create($destination, $server, $teamId, new BackupSpec('database', new BackupTarget(engine: $target['engine'], workload: $target['workload']), $this->destination($destination), $tags));
    }

    /**
     * @param  array{engine: string, workload: string}  $target
     * @param  'new_service'|'in_place'  $mode
     */
    public function restore(BackupDestination $destination, Server $server, int $teamId, string $snapshotId, array $target, string $mode): DaemonTask
    {
        return $this->create($destination, $server, $teamId, new RestoreSpec('database', $snapshotId, new BackupTarget(engine: $target['engine'], workload: $target['workload']), $this->destination($destination), $mode));
    }

    /**
     * A volume snapshot (docs/14 §2), pinned to the volume's server.
     *
     * @param  'live'|'pause'  $consistency
     * @param  list<string>  $tags
     */
    public function volumeBackup(BackupDestination $destination, Server $server, int $teamId, string $volumeId, string $consistency, array $tags): DaemonTask
    {
        return $this->create($destination, $server, $teamId, new BackupSpec('volume', new BackupTarget(volume: $volumeId, consistency: $consistency), $this->destination($destination), $tags));
    }

    /**
     * Replaces a volume's content with a snapshot; the service is held (not
     * rendered) while it runs.
     *
     * @param  'new_service'|'in_place'  $mode
     */
    public function volumeRestore(BackupDestination $destination, Server $server, int $teamId, string $snapshotId, string $volumeId, string $mode): DaemonTask
    {
        return $this->create($destination, $server, $teamId, new RestoreSpec('volume', $snapshotId, new BackupTarget(volume: $volumeId), $this->destination($destination), $mode));
    }

    /**
     * A physical Postgres base backup (pg_basebackup, docs/14 §10), pinned to
     * the server that runs the database.
     *
     * @param  list<string>  $tags
     */
    public function baseBackup(BackupDestination $destination, Server $server, int $teamId, string $workloadId, array $tags): DaemonTask
    {
        return $this->create($destination, $server, $teamId, new BackupSpec('base_backup', new BackupTarget(engine: 'postgres', workload: $workloadId), $this->destination($destination), $tags));
    }

    /**
     * Replaces a Postgres data volume with a base backup replayed to a point
     * in time (docs/14 §10.3). The database is held (not rendered) while it
     * runs; the replay runs with the target's POSTGRES_USER/PASSWORD/DB,
     * sealed at lease time too.
     *
     * @param  'new_service'|'in_place'  $mode
     * @param  list<string>  $walTags
     */
    public function pointInTimeRestore(BackupDestination $destination, Server $server, ServiceInstance $target, string $snapshotId, string $volumeId, string $mode, ?string $pointInTime, array $walTags, RestoreRuntime $runtime): DaemonTask
    {
        return $this->create(
            $destination,
            $server,
            $target->team_id,
            new RestoreSpec('base_backup', $snapshotId, new BackupTarget(engine: 'postgres', volume: $volumeId), $this->destination($destination), $mode, $pointInTime, $walTags, $runtime),
            restoreRuntimeEnvOf: $target,
        );
    }

    /**
     * Restores a snapshot into a throwaway container and checks it (docs/14
     * §10.4). Not retried; one per service at a time.
     */
    public function verify(BackupDestination $destination, Server $server, int $teamId, VerifyRestoreSpec $spec): DaemonTask
    {
        return $this->create($destination, $server, $teamId, $spec, config()->integer('launchroom.backups.verification.timeout_seconds'));
    }

    /**
     * @param  list<string>  $tags
     */
    public function snapshots(BackupDestination $destination, Server $server, int $teamId, array $tags): DaemonTask
    {
        return $this->create($destination, $server, $teamId, new BackupSnapshotsSpec($this->destination($destination), $tags), 600);
    }

    /**
     * @param  'forget'|'prune'|'check'  $operation
     * @param  list<string>  $tags
     */
    public function maintenance(BackupDestination $destination, Server $server, int $teamId, string $operation, ?RetentionData $retention = null, array $tags = []): DaemonTask
    {
        return $this->create($destination, $server, $teamId, new BackupMaintenanceSpec(
            $operation,
            $this->destination($destination),
            $retention instanceof RetentionData ? Retention::from($retention->toProtocol()) : null,
            $tags === [] ? null : $tags,
        ));
    }

    /**
     * A destination as a task spec field (also of exports of backups,
     * docs/14 §12); its credentials are sealed at lease time
     * ({@see self::seal()}).
     */
    public function destination(BackupDestination $destination): DestinationSpec
    {
        $prefix = trim($destination->prefix, '/');

        return new DestinationSpec(
            kind: 's3',
            endpoint: $destination->endpoint,
            bucket: $destination->bucket,
            region: $destination->region,
            prefix: $prefix === '' ? null : $prefix,
        );
    }

    /**
     * What a task reading $destination gets sealed at lease time.
     */
    public static function seal(BackupDestination $destination, ?ServiceInstance $restoreRuntimeEnvOf = null): TaskSeal
    {
        return new TaskSeal(destination: $destination->id, restoreRuntimeEnv: $restoreRuntimeEnvOf?->id);
    }

    public static function verificationResources(): Resources
    {
        return new Resources(
            config()->float('launchroom.backups.verification.cpus'),
            config()->integer('launchroom.backups.verification.memory_mb'),
            config()->integer('launchroom.backups.verification.pids'),
        );
    }

    private function create(BackupDestination $destination, Server $server, int $teamId, TaskSpec $spec, ?int $timeoutSeconds = null, ?ServiceInstance $restoreRuntimeEnvOf = null): DaemonTask
    {
        if ($destination->team_id !== null && $destination->team_id !== $teamId) {
            throw new InvalidArgumentException('A team can only use its own backup destinations.');
        }

        return $this->tasks->create(
            $teamId,
            $spec,
            $timeoutSeconds ?? config()->integer('launchroom.backups.task_timeout_seconds'),
            pin: $server,
            seal: self::seal($destination, $restoreRuntimeEnvOf),
        );
    }
}
