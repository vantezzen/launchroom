<?php

declare(strict_types=1);

namespace App\Data\Updates;

use App\Enums\Backups\BackupRunStatus;
use App\Models\Backups\BackupRun;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A control-plane backup the update dialog offers instead of a new one
 * (docs/11 §4.1): the latest succeeded backup, or the one running now.
 */
final class PreUpgradeBackupData extends Data
{
    public function __construct(
        public string $id,
        public BackupRunStatus $status,
        public ?CarbonInterface $startedAt,
        public ?CarbonInterface $finishedAt,
        public ?int $sizeBytes,
    ) {}

    public static function fromRun(BackupRun $run): self
    {
        return new self($run->public_id, $run->status, $run->started_at ?? $run->created_at, $run->finished_at, $run->size_bytes);
    }
}
