<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Models\Backups\BackupRun;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One backup run in lists (never secrets; errors are masked at the source).
 */
final class BackupRunData extends Data
{
    public function __construct(
        public string $id,
        public BackupReason $reason,
        public string $reasonLabel,
        public BackupRunStatus $status,
        public ?string $snapshotId,
        public ?string $shortSnapshotId,
        public ?int $sizeBytes,
        public ?string $server,
        public ?string $error,
        public ?CarbonInterface $createdAt,
        public ?CarbonInterface $startedAt,
        public ?CarbonInterface $finishedAt,
        /** database (dump), volume, base_backup (point-in-time recovery). */
        public BackupSubject $subject = BackupSubject::Database,
        public ?int $durationMs = null,
    ) {}

    public static function fromRun(BackupRun $run): self
    {
        return new self(
            $run->public_id,
            $run->reason,
            $run->reason->label(),
            $run->status,
            $run->snapshot_id,
            $run->snapshot_id === null ? null : substr($run->snapshot_id, 0, 8),
            $run->size_bytes,
            $run->relationLoaded('server') ? $run->server?->name : null,
            $run->error,
            $run->created_at,
            $run->started_at,
            $run->finished_at,
            $run->subject,
            $run->duration_ms,
        );
    }
}
