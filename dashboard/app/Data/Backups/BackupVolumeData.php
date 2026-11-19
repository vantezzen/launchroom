<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\VolumeConsistency;
use Carbon\CarbonInterface;
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
        /** "Back up this volume". */
        public bool $backupEnabled = true,
        public ?string $turnedOffBy = null,
        public ?CarbonInterface $turnedOffAt = null,
        /** PUT {enabled} here (canConfigure); send Accept: application/json for this tab's data back. */
        public ?string $backupUrl = null,
        /** Current size on the server (disk breakdown). */
        public ?int $liveSizeBytes = null,
        /** Size of its newest successful backup. */
        public ?int $lastSizeBytes = null,
        public ?CarbonInterface $lastSucceededAt = null,
        public bool $looksLikeCache = false,
    ) {}
}
