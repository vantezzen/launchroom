<?php

declare(strict_types=1);

namespace App\Data\Updates;

use App\Enums\Backups\BackupRunStatus;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Models\Updates\ControlPlaneUpdate;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A control-plane update for Settings → Updates. `progress` is the last
 * line launchroomd reported while installing; `backupStatus` and
 * `backupFinishedAt` describe the pre-update backup (null: none taken);
 * `existingBackup` is true when it is a backup started before the update
 * (chosen in the dialog, or one that was running and was waited for);
 * `fleetUpdateId` is the server rollout it started (`updateServers`).
 *
 * Never stuck (docs/11 §4.3): `lastActivityAt` is the last sign of life of
 * the current step (null while launchroomd installs), `stalled` true when
 * that is longer ago than the stall time, `backupBytes` how much of the
 * pre-update backup is stored, `backupAttempts` how often it was started
 * again after its worker stopped, and `canCancel` whether "Cancel update"
 * is possible (nothing has changed yet).
 */
final class ControlPlaneUpdateData extends Data
{
    public function __construct(
        public string $id,
        public string $fromVersion,
        public string $toVersion,
        public ControlPlaneUpdateStatus $status,
        public bool $skipBackup,
        public bool $updateServers,
        public bool $automatic,
        public ?BackupRunStatus $backupStatus,
        public ?CarbonInterface $backupFinishedAt,
        public bool $existingBackup,
        public ?string $fleetUpdateId,
        public ?string $progress,
        public ?string $error,
        public ?CarbonInterface $createdAt,
        public ?CarbonInterface $startedAt,
        public ?CarbonInterface $finishedAt,
        public ?CarbonInterface $lastActivityAt,
        public bool $stalled,
        public ?int $backupBytes,
        public int $backupAttempts,
        public bool $canCancel,
    ) {}

    public static function fromUpdate(ControlPlaneUpdate $update): self
    {
        return new self(
            $update->public_id,
            $update->from_version,
            $update->to_version,
            $update->status,
            $update->skip_backup,
            $update->update_servers,
            $update->automatic,
            $update->backupRun?->status,
            $update->backupRun?->finished_at,
            $update->backupRun?->created_at !== null && $update->created_at !== null && $update->backupRun->created_at->lt($update->created_at),
            $update->fleetUpdate?->public_id,
            $update->status->isTerminal() ? null : $update->daemonTask?->progress,
            $update->error,
            $update->created_at,
            $update->started_at,
            $update->finished_at,
            $update->lastActivityAt(),
            $update->stalled(),
            $update->backupRun?->status === BackupRunStatus::Running ? $update->backupRun->progress_bytes : null,
            $update->backup_attempts,
            ! $update->status->isTerminal() && $update->cancellable(),
        );
    }
}
