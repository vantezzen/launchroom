<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Data\Backups\RetentionData;
use App\Enums\DaemonTaskType;
use App\Models\Backups\BackupDestination;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Support\Crypto\PublicKeyFormat;
use App\Support\PublicId;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Creates backup daemon tasks (docs/14 §7, protocol task.json): typed specs
 * whose destination credentials are a TaskEnv sealed to the executing daemon
 * and bound to the task id (protocol/README.md "Sealed task secrets"), so a
 * spec can't be replayed on another server or as another task.
 *
 * Tasks that touch a database are pinned to the server that runs it;
 * repository-only tasks (snapshots, maintenance) are pinned to a server
 * chosen by the caller (any online server of the team).
 */
final readonly class BackupTasks
{
    public function __construct(
        private DestinationSecrets $secrets,
    ) {}

    /**
     * @param  array{engine: string, workload: string}  $target
     * @param  list<string>  $tags
     */
    public function backup(BackupDestination $destination, Server $server, int $teamId, array $target, array $tags): DaemonTask
    {
        return $this->create($destination, $server, $teamId, DaemonTaskType::Backup, config()->integer('launchroom.backups.task_timeout_seconds'), fn (string $taskId): array => [
            'kind' => 'database',
            'target' => $target,
            'destination' => $this->destination($destination, $server, $taskId),
            'tags' => $tags,
        ]);
    }

    /**
     * @param  array{engine: string, workload: string}  $target
     * @param  'new_service'|'in_place'  $mode
     */
    public function restore(BackupDestination $destination, Server $server, int $teamId, string $snapshotId, array $target, string $mode): DaemonTask
    {
        return $this->create($destination, $server, $teamId, DaemonTaskType::Restore, config()->integer('launchroom.backups.task_timeout_seconds'), fn (string $taskId): array => [
            'kind' => 'database',
            'snapshot' => $snapshotId,
            'target' => $target,
            'destination' => $this->destination($destination, $server, $taskId),
            'mode' => $mode,
        ]);
    }

    /**
     * A volume snapshot (docs/14 §2), pinned to the volume's server.
     *
     * @param  'live'|'pause'  $consistency
     * @param  list<string>  $tags
     */
    public function volumeBackup(BackupDestination $destination, Server $server, int $teamId, string $volumeId, string $consistency, array $tags): DaemonTask
    {
        return $this->create($destination, $server, $teamId, DaemonTaskType::Backup, config()->integer('launchroom.backups.task_timeout_seconds'), fn (string $taskId): array => [
            'kind' => 'volume',
            'target' => ['volume' => $volumeId, 'consistency' => $consistency],
            'destination' => $this->destination($destination, $server, $taskId),
            'tags' => $tags,
        ]);
    }

    /**
     * Replaces a volume's content with a snapshot; the service is held (not
     * rendered) while it runs.
     *
     * @param  'new_service'|'in_place'  $mode
     */
    public function volumeRestore(BackupDestination $destination, Server $server, int $teamId, string $snapshotId, string $volumeId, string $mode): DaemonTask
    {
        return $this->create($destination, $server, $teamId, DaemonTaskType::Restore, config()->integer('launchroom.backups.task_timeout_seconds'), fn (string $taskId): array => [
            'kind' => 'volume',
            'snapshot' => $snapshotId,
            'target' => ['volume' => $volumeId],
            'destination' => $this->destination($destination, $server, $taskId),
            'mode' => $mode,
        ]);
    }

    /**
     * A physical Postgres base backup (pg_basebackup, docs/14 §10), pinned to
     * the server that runs the database.
     *
     * @param  list<string>  $tags
     */
    public function baseBackup(BackupDestination $destination, Server $server, int $teamId, string $workloadId, array $tags): DaemonTask
    {
        return $this->create($destination, $server, $teamId, DaemonTaskType::Backup, config()->integer('launchroom.backups.task_timeout_seconds'), fn (string $taskId): array => [
            'kind' => 'base_backup',
            'target' => ['engine' => 'postgres', 'workload' => $workloadId],
            'destination' => $this->destination($destination, $server, $taskId),
            'tags' => $tags,
        ]);
    }

    /**
     * Replaces a Postgres data volume with a base backup replayed to a point
     * in time (docs/14 §10.3). The database is held (not rendered) while it
     * runs; the runtime env (the target's POSTGRES_USER/PASSWORD/DB) is a
     * second TaskEnv sealed to the server and task.
     *
     * @param  'new_service'|'in_place'  $mode
     * @param  list<string>  $walTags
     * @param  array{image: string, mountPath: string, dataDirectory: string, archiveDirectory: string|null, cpus: float, memoryMb: int, pids: int}  $runtime
     * @param  array{POSTGRES_USER: string, POSTGRES_PASSWORD: string, POSTGRES_DB: string}  $targetEnv
     */
    public function pointInTimeRestore(BackupDestination $destination, Server $server, int $teamId, string $snapshotId, string $volumeId, string $mode, ?string $pointInTime, array $walTags, array $runtime, #[SensitiveParameter] array $targetEnv): DaemonTask
    {
        return $this->create($destination, $server, $teamId, DaemonTaskType::Restore, config()->integer('launchroom.backups.task_timeout_seconds'), fn (string $taskId): array => array_filter([
            'kind' => 'base_backup',
            'snapshot' => $snapshotId,
            'target' => ['engine' => 'postgres', 'volume' => $volumeId],
            'destination' => $this->destination($destination, $server, $taskId),
            'mode' => $mode,
            'pointInTime' => $pointInTime,
            'walTags' => $walTags,
            'runtime' => array_filter([
                'image' => $runtime['image'],
                'mountPath' => $runtime['mountPath'],
                'dataDirectory' => $runtime['dataDirectory'],
                'archiveDirectory' => $runtime['archiveDirectory'],
                'env' => ['sealed' => $this->sealTaskEnv($server, $taskId, $targetEnv)],
                'resources' => ['cpus' => $runtime['cpus'], 'memoryMb' => $runtime['memoryMb'], 'pids' => $runtime['pids']],
            ], static fn (mixed $value): bool => $value !== null),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Restores a snapshot into a throwaway container and checks it (docs/14
     * §10.4). Not retried; one per service at a time.
     *
     * @param  array<string, mixed>  $spec  kind, snapshot, engine/image/dataPath/… (without destination)
     */
    public function verify(BackupDestination $destination, Server $server, int $teamId, array $spec): DaemonTask
    {
        return $this->create($destination, $server, $teamId, DaemonTaskType::VerifyRestore, config()->integer('launchroom.backups.verification.timeout_seconds'), fn (string $taskId): array => [
            ...$spec,
            'destination' => $this->destination($destination, $server, $taskId),
        ]);
    }

    /**
     * @param  list<string>  $tags
     */
    public function snapshots(BackupDestination $destination, Server $server, int $teamId, array $tags): DaemonTask
    {
        return $this->create($destination, $server, $teamId, DaemonTaskType::BackupSnapshots, 600, fn (string $taskId): array => [
            'destination' => $this->destination($destination, $server, $taskId),
            'tags' => $tags,
        ]);
    }

    /**
     * @param  'forget'|'prune'|'check'  $operation
     * @param  list<string>  $tags
     */
    public function maintenance(BackupDestination $destination, Server $server, int $teamId, string $operation, ?RetentionData $retention = null, array $tags = []): DaemonTask
    {
        return $this->create($destination, $server, $teamId, DaemonTaskType::BackupMaintenance, config()->integer('launchroom.backups.task_timeout_seconds'), fn (string $taskId): array => array_filter([
            'operation' => $operation,
            'destination' => $this->destination($destination, $server, $taskId),
            'retention' => $retention?->toProtocol(),
            'tags' => $tags === [] ? null : $tags,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  callable(string): array<string, mixed>  $spec
     */
    private function create(BackupDestination $destination, Server $server, int $teamId, DaemonTaskType $type, int $timeoutSeconds, callable $spec): DaemonTask
    {
        if ($destination->team_id !== null && $destination->team_id !== $teamId) {
            throw new InvalidArgumentException('A team can only use its own backup destinations.');
        }

        if ($server->team_id !== $teamId) {
            throw new InvalidArgumentException('Backup tasks run on servers of the same team.');
        }

        // The id is known before the row exists: the sealed credentials bind it.
        $taskId = PublicId::generate(DaemonTask::publicIdPrefix());

        $task = new DaemonTask([
            'team_id' => $teamId,
            'server_id' => $server->id,
            'pinned_to_server' => true,
            'type' => $type,
            'spec' => json_decode(json_encode($spec($taskId), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), false, 64, JSON_THROW_ON_ERROR),
            // Backups are not retried blindly: a retry would take another
            // snapshot or re-run a restore. The run reports the failure.
            'max_attempts' => 1,
            'timeout_seconds' => $timeoutSeconds,
        ]);
        $task->public_id = $taskId;
        $task->save();

        return $task;
    }

    /**
     * A destination as a task spec field, its credentials sealed to $server
     * and $taskId (also used by data exports of backups, docs/14 §12).
     *
     * @return array<string, mixed>
     */
    public function destination(BackupDestination $destination, Server $server, string $taskId): array
    {
        $credentials = $this->secrets->reveal($destination);

        return array_filter([
            'kind' => 's3',
            'endpoint' => $destination->endpoint,
            'bucket' => $destination->bucket,
            'region' => $destination->region,
            'prefix' => trim($destination->prefix, '/') === '' ? null : trim($destination->prefix, '/'),
            'credentials' => ['sealed' => $this->sealTaskEnv($server, $taskId, [
                'AWS_ACCESS_KEY_ID' => $credentials->accessKeyId,
                'AWS_SECRET_ACCESS_KEY' => $credentials->secretAccessKey,
                'RESTIC_PASSWORD' => $credentials->repositoryPassword,
            ])],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * protocol/schema/v1/task-env.json, sealed with crypto_box_seal.
     *
     * @param  array<string, string>  $env
     */
    public function sealTaskEnv(Server $server, string $taskId, #[SensitiveParameter] array $env): string
    {
        $publicKey = PublicKeyFormat::parseX25519($server->sealing_public_key);

        if ($publicKey === null) {
            throw new InvalidArgumentException('The server has no valid X25519 sealing key.');
        }

        $document = json_encode(
            ['serverId' => $server->public_id, 'taskId' => $taskId, 'env' => (object) $env],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return base64_encode(sodium_crypto_box_seal($document, $publicKey));
    }
}
