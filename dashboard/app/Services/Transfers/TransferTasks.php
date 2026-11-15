<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Data\Protocol\V1\Resources;
use App\Data\Protocol\V1\Sealed;
use App\Data\Protocol\V1\Tasks\DbExportSpec;
use App\Data\Protocol\V1\Tasks\DbImportSource;
use App\Data\Protocol\V1\Tasks\DbImportSpec;
use App\Data\Protocol\V1\Tasks\FileOwner;
use App\Data\Protocol\V1\Tasks\TaskSpec;
use App\Data\Protocol\V1\Tasks\TransferRef;
use App\Data\Protocol\V1\Tasks\TransferTarget;
use App\Data\Protocol\V1\Tasks\VolumeExportSpec;
use App\Data\Protocol\V1\Tasks\VolumeImportSpec;
use App\Models\Backups\BackupDestination;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\Transfers\DataTransfer;
use App\Services\Backups\BackupTasks;
use App\Services\Tasks\DaemonTasks;
use App\Support\PublicId;
use InvalidArgumentException;

/**
 * Creates the data import and export tasks (D55, docs/14 §12.2), pinned to
 * the server that holds the data. A backup destination's credentials are
 * sealed at lease time. A connection URL to import from is the exception:
 * the dashboard never keeps it, so the caller seals it to the server
 * (App\Support\Crypto\TaskSealer, a TaskEnv holding exactly SOURCE_URL) for
 * a task id reserved with {@see self::reserveTaskId()}, and it waits sealed
 * until the task exists.
 */
final readonly class TransferTasks
{
    public function __construct(
        private BackupTasks $backupTasks,
        private DaemonTasks $tasks,
    ) {}

    public static function reserveTaskId(): string
    {
        return PublicId::generate(DaemonTask::publicIdPrefix());
    }

    /**
     * @param  string  $engine  an importable engine ({@see ImportTarget::importEngine()})
     */
    public function inspectDatabase(Server $server, int $teamId, string $engine, string $workloadId, ?string $taskId = null, ?string $sealedUrl = null): DaemonTask
    {
        return $this->create($server, $teamId, new DbImportSpec(
            mode: 'inspect',
            engine: $engine,
            workload: $workloadId,
            resources: $this->resources(),
            source: $sealedUrl === null ? null : new DbImportSource('url', url: new Sealed($sealedUrl)),
        ), config()->integer('launchroom.transfers.inspect_timeout_seconds'), publicId: $taskId);
    }

    public function inspectVolume(Server $server, int $teamId, string $volumeId): DaemonTask
    {
        return $this->create($server, $teamId, new VolumeImportSpec('inspect', $volumeId), config()->integer('launchroom.transfers.inspect_timeout_seconds'));
    }

    /**
     * @param  string  $engine  an importable engine ({@see ImportTarget::importEngine()})
     */
    public function importDatabase(Server $server, int $teamId, string $engine, string $workloadId, ?DataTransfer $file, ?string $sealedUrl, bool $replace, ?string $taskId): DaemonTask
    {
        if ($file instanceof DataTransfer !== ($sealedUrl === null)) {
            throw new InvalidArgumentException('An import reads exactly one of a file and a URL.');
        }

        return $this->create($server, $teamId, new DbImportSpec(
            mode: 'import',
            engine: $engine,
            workload: $workloadId,
            resources: $this->resources(),
            source: $file instanceof DataTransfer ? new DbImportSource('file', file: TransferRef::of($file)) : new DbImportSource('url', url: new Sealed((string) $sealedUrl)),
            replace: $replace ?: null,
        ), config()->integer('launchroom.transfers.task_timeout_seconds'), publicId: $taskId);
    }

    /**
     * @param  array{uid: int, gid: int}|null  $owner
     */
    public function importVolume(Server $server, int $teamId, string $volumeId, DataTransfer $file, bool $replace, int $stripComponents, ?array $owner): DaemonTask
    {
        return $this->create($server, $teamId, new VolumeImportSpec(
            mode: 'import',
            volume: $volumeId,
            file: TransferRef::of($file),
            replace: $replace ?: null,
            stripComponents: $stripComponents > 0 ? $stripComponents : null,
            owner: $owner === null ? null : new FileOwner($owner['uid'], $owner['gid']),
        ), config()->integer('launchroom.transfers.task_timeout_seconds'));
    }

    /**
     * A live dump ($workloadId) or a backup ($snapshot from $destination).
     */
    public function exportDatabase(Server $server, int $teamId, string $engine, ?string $workloadId, ?string $snapshot, ?BackupDestination $destination, DataTransfer $transfer): DaemonTask
    {
        return $this->create($server, $teamId, new DbExportSpec(
            engine: $engine,
            transfer: TransferTarget::of($transfer),
            workload: $workloadId,
            snapshot: $snapshot,
            destination: $destination instanceof BackupDestination ? $this->backupTasks->destination($destination) : null,
        ), config()->integer('launchroom.transfers.task_timeout_seconds'), $destination);
    }

    /**
     * @param  'live'|'pause'|null  $consistency
     */
    public function exportVolume(Server $server, int $teamId, ?string $volumeId, ?string $consistency, ?string $snapshot, ?BackupDestination $destination, DataTransfer $transfer): DaemonTask
    {
        return $this->create($server, $teamId, new VolumeExportSpec(
            transfer: TransferTarget::of($transfer),
            volume: $volumeId,
            consistency: $snapshot === null ? $consistency : null,
            snapshot: $snapshot,
            destination: $destination instanceof BackupDestination ? $this->backupTasks->destination($destination) : null,
        ), config()->integer('launchroom.transfers.task_timeout_seconds'), $destination);
    }

    private function resources(): Resources
    {
        return new Resources(
            config()->float('launchroom.transfers.resources.cpus'),
            config()->integer('launchroom.transfers.resources.memory_mb'),
            config()->integer('launchroom.transfers.resources.pids'),
        );
    }

    private function create(Server $server, int $teamId, TaskSpec $spec, int $timeoutSeconds, ?BackupDestination $destination = null, ?string $publicId = null): DaemonTask
    {
        return $this->tasks->create(
            $teamId,
            $spec,
            $timeoutSeconds,
            pin: $server,
            seal: $destination instanceof BackupDestination ? BackupTasks::seal($destination) : null,
            publicId: $publicId,
        );
    }
}
