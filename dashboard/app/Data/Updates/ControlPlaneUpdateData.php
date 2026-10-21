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
 * `fleetUpdateId` is the server rollout it started (`updateServers`).
 */
final class ControlPlaneUpdateData extends Data
{
    public function __construct(
        public string $id,
        public string $fromVersion,
        public string $toVersion,
        public ControlPlaneUpdateStatus $status,
        public string $statusLabel,
        public bool $skipBackup,
        public bool $updateServers,
        public bool $automatic,
        public ?BackupRunStatus $backupStatus,
        public ?CarbonInterface $backupFinishedAt,
        public ?string $fleetUpdateId,
        public ?string $progress,
        public ?string $error,
        public ?CarbonInterface $createdAt,
        public ?CarbonInterface $startedAt,
        public ?CarbonInterface $finishedAt,
    ) {}

    public static function fromUpdate(ControlPlaneUpdate $update): self
    {
        return new self(
            $update->public_id,
            $update->from_version,
            $update->to_version,
            $update->status,
            $update->status->label(),
            $update->skip_backup,
            $update->update_servers,
            $update->automatic,
            $update->backupRun?->status,
            $update->backupRun?->finished_at,
            $update->fleetUpdate?->public_id,
            $update->status->isTerminal() ? null : $update->daemonTask?->progress,
            $update->error,
            $update->created_at,
            $update->started_at,
            $update->finished_at,
        );
    }
}
