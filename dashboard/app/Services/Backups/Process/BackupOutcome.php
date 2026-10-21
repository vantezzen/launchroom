<?php

declare(strict_types=1);

namespace App\Services\Backups\Process;

/**
 * A stored snapshot.
 */
final readonly class BackupOutcome
{
    public function __construct(
        public string $snapshotId,
        public int $sizeBytes,
        public int $addedBytes,
    ) {}
}
