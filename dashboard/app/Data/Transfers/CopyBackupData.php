<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use Spatie\LaravelData\Data;

/**
 * A backup of the source a copy can read (docs/14 §14.1).
 */
final class CopyBackupData extends Data
{
    public function __construct(
        /** The restic snapshot id (not a secret). */
        public string $snapshot,
        public string $takenAt,
        public ?int $sizeBytes,
        /** Volume backups: the source volume's mount path. */
        public ?string $mountPath = null,
    ) {}
}
