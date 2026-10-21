<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One finished backup run in the Backups tab's trend (size, duration,
 * success over time; docs/14 §10.6), oldest first.
 */
final class BackupTrendPointData extends Data
{
    public function __construct(
        public string $id,
        public BackupSubject $subject,
        public BackupRunStatus $status,
        public CarbonInterface $at,
        public ?int $sizeBytes,
        public ?int $durationMs,
    ) {}
}
