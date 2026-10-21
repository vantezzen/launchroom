<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Models\Backups\RestoreRun;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

final class RestoreRunData extends Data
{
    public function __construct(
        public string $id,
        public RestoreMode $mode,
        public RestoreRunStatus $status,
        public string $shortSnapshotId,
        public ?string $targetServiceId,
        public ?string $targetServiceName,
        public ?string $error,
        public ?CarbonInterface $createdAt,
        public ?CarbonInterface $finishedAt,
        /** Set when the restored copy can be swapped in for the original (docs/14 §9.16). */
        public ?string $swapUrl = null,
        /** Point-in-time restores: the time asked for (null: the newest point) and the last transaction replayed. */
        public bool $pointInTime = false,
        public ?CarbonInterface $requestedTime = null,
        public ?CarbonInterface $restoredTo = null,
    ) {}

    public static function fromRun(RestoreRun $run, ?string $swapUrl = null): self
    {
        $target = $run->relationLoaded('target') ? $run->target : null;
        $service = $target !== null && $target->relationLoaded('service') ? $target->service : null;

        return new self(
            $run->public_id,
            $run->mode,
            $run->status,
            substr($run->snapshot_id, 0, 8),
            $service?->public_id,
            $service?->name,
            $run->error,
            $run->created_at,
            $run->finished_at,
            $swapUrl,
            $run->subject === BackupSubject::BaseBackup,
            $run->point_in_time,
            $run->restored_to,
        );
    }
}
