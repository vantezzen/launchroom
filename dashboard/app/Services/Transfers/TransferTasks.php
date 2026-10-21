<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\DaemonTaskType;
use App\Models\Backups\BackupDestination;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\Transfers\DataTransfer;
use App\Services\Backups\BackupTasks;
use App\Support\PublicId;
use Closure;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Creates the data import and export tasks (D55, docs/14 §12.2): typed
 * specs pinned to the server that holds the data. A connection URL is
 * sealed to that server and a task id reserved in advance
 * ({@see self::sealSourceUrl()}), so it can wait sealed until the task exists.
 */
final readonly class TransferTasks
{
    public function __construct(
        private BackupTasks $backupTasks,
    ) {}

    public static function reserveTaskId(): string
    {
        return PublicId::generate(DaemonTask::publicIdPrefix());
    }

    /**
     * The `source.url` of a db_import spec: a TaskEnv holding exactly
     * SOURCE_URL, sealed to $server for $taskId. The dashboard can't open it.
     */
    public function sealSourceUrl(Server $server, string $taskId, #[SensitiveParameter] string $url): string
    {
        return $this->backupTasks->sealTaskEnv($server, $taskId, ['SOURCE_URL' => $url]);
    }

    /**
     * @param  'postgres'|'mysql'|'mongodb'  $engine
     */
    public function inspectDatabase(Server $server, int $teamId, string $engine, string $workloadId, ?string $taskId = null, ?string $sealedUrl = null): DaemonTask
    {
        return $this->create($server, $teamId, DaemonTaskType::DbImport, config()->integer('launchroom.transfers.inspect_timeout_seconds'), 1, array_filter([
            'mode' => 'inspect',
            'engine' => $engine,
            'workload' => $workloadId,
            'source' => $sealedUrl === null ? null : ['kind' => 'url', 'url' => ['sealed' => $sealedUrl]],
            'resources' => $this->resources(),
        ], static fn (mixed $value): bool => $value !== null), $taskId);
    }

    public function inspectVolume(Server $server, int $teamId, string $volumeId): DaemonTask
    {
        return $this->create($server, $teamId, DaemonTaskType::VolumeImport, config()->integer('launchroom.transfers.inspect_timeout_seconds'), 1, [
            'mode' => 'inspect',
            'volume' => $volumeId,
        ]);
    }

    /**
     * @param  'postgres'|'mysql'|'mongodb'  $engine
     */
    public function importDatabase(Server $server, int $teamId, string $engine, string $workloadId, ?DataTransfer $file, ?string $sealedUrl, bool $replace, ?string $taskId): DaemonTask
    {
        if ($file instanceof DataTransfer !== ($sealedUrl === null)) {
            throw new InvalidArgumentException('An import reads exactly one of a file and a URL.');
        }

        return $this->create($server, $teamId, DaemonTaskType::DbImport, config()->integer('launchroom.transfers.task_timeout_seconds'), 1, array_filter([
            'mode' => 'import',
            'engine' => $engine,
            'workload' => $workloadId,
            'source' => $file instanceof DataTransfer
                ? ['kind' => 'file', 'file' => self::reference($file)]
                : ['kind' => 'url', 'url' => ['sealed' => $sealedUrl]],
            'replace' => $replace ?: null,
            'resources' => $this->resources(),
        ], static fn (mixed $value): bool => $value !== null), $taskId);
    }

    /**
     * @param  array{uid: int, gid: int}|null  $owner
     */
    public function importVolume(Server $server, int $teamId, string $volumeId, DataTransfer $file, bool $replace, int $stripComponents, ?array $owner): DaemonTask
    {
        return $this->create($server, $teamId, DaemonTaskType::VolumeImport, config()->integer('launchroom.transfers.task_timeout_seconds'), 1, array_filter([
            'mode' => 'import',
            'volume' => $volumeId,
            'file' => self::reference($file),
            'replace' => $replace ?: null,
            'stripComponents' => $stripComponents > 0 ? $stripComponents : null,
            'owner' => $owner,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * A live dump ($workloadId) or a backup ($snapshot from $destination).
     */
    public function exportDatabase(Server $server, int $teamId, string $engine, ?string $workloadId, ?string $snapshot, ?BackupDestination $destination, DataTransfer $transfer): DaemonTask
    {
        return $this->create($server, $teamId, DaemonTaskType::DbExport, config()->integer('launchroom.transfers.task_timeout_seconds'), 2, fn (string $taskId): array => array_filter([
            'engine' => $engine,
            'workload' => $workloadId,
            'snapshot' => $snapshot,
            'destination' => $destination instanceof BackupDestination ? $this->backupTasks->destination($destination, $server, $taskId) : null,
            'transfer' => ['id' => $transfer->public_id, 'chunkBytes' => $transfer->chunk_bytes],
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  'live'|'pause'|null  $consistency
     */
    public function exportVolume(Server $server, int $teamId, ?string $volumeId, ?string $consistency, ?string $snapshot, ?BackupDestination $destination, DataTransfer $transfer): DaemonTask
    {
        return $this->create($server, $teamId, DaemonTaskType::VolumeExport, config()->integer('launchroom.transfers.task_timeout_seconds'), 2, fn (string $taskId): array => array_filter([
            'volume' => $volumeId,
            'consistency' => $snapshot === null ? $consistency : null,
            'snapshot' => $snapshot,
            'destination' => $destination instanceof BackupDestination ? $this->backupTasks->destination($destination, $server, $taskId) : null,
            'transfer' => ['id' => $transfer->public_id, 'chunkBytes' => $transfer->chunk_bytes],
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * protocol TransferRef.
     *
     * @return array{id: string, sizeBytes: int, chunkBytes: int, chunks: int, sha256: string}
     */
    public static function reference(DataTransfer $transfer): array
    {
        return [
            'id' => $transfer->public_id,
            'sizeBytes' => $transfer->size_bytes,
            'chunkBytes' => $transfer->chunk_bytes,
            'chunks' => $transfer->chunk_count,
            'sha256' => (string) $transfer->digest,
        ];
    }

    /**
     * @return array{cpus: float, memoryMb: int, pids: int}
     */
    private function resources(): array
    {
        return [
            'cpus' => (float) config()->float('launchroom.transfers.resources.cpus'),
            'memoryMb' => config()->integer('launchroom.transfers.resources.memory_mb'),
            'pids' => config()->integer('launchroom.transfers.resources.pids'),
        ];
    }

    /**
     * @param  array<string, mixed>|Closure(string): array<string, mixed>  $spec
     */
    private function create(Server $server, int $teamId, DaemonTaskType $type, int $timeoutSeconds, int $maxAttempts, array|Closure $spec, ?string $taskId = null): DaemonTask
    {
        if ($server->team_id !== $teamId) {
            throw new InvalidArgumentException('Transfer tasks run on servers of the same team.');
        }

        $taskId ??= self::reserveTaskId();
        $document = $spec instanceof Closure ? $spec($taskId) : $spec;

        $task = new DaemonTask([
            'team_id' => $teamId,
            'server_id' => $server->id,
            'pinned_to_server' => true,
            'type' => $type,
            'spec' => json_decode(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), false, 64, JSON_THROW_ON_ERROR),
            // Imports are not retried blindly (they would import again);
            // exports may be (chunks are idempotent).
            'max_attempts' => $maxAttempts,
            'timeout_seconds' => $timeoutSeconds,
        ]);
        $task->public_id = $taskId;
        $task->save();

        return $task;
    }
}
