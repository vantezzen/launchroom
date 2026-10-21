<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\VolumeConsistency;
use Spatie\LaravelData\Data;

/**
 * One volume in the Backups tab of a service with volumes (docs/14 §2).
 */
final class BackupVolumeData extends Data
{
    public function __construct(
        public string $id,
        public string $mountPath,
        public VolumeConsistency $consistency,
        /** Server name, or null before the first deploy. */
        public ?string $server,
        /** PUT {consistency} here (canConfigure). */
        public string $updateUrl,
    ) {}
}
